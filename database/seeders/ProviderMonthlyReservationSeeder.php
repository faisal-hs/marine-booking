<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Database\Seeders\Concerns\PricesReservations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gives EVERY ACTIVE PROVIDER at least PROVIDER_MONTH_MINIMUM (50) paid reservations in the CURRENT MONTH.
 *
 *     php artisan db:seed --class=ProviderMonthlyReservationSeeder
 *
 * An "active provider" is one an admin has approved whose account is active (not suspended, not deleted).
 * Pending, rejected, suspended and deleted providers get nothing.
 *
 * What it creates, for each of them:
 *  - reservations dated within the current month, each on a real departure of one of the provider's published
 *    activities and on that departure's weekday, priced from the option's own pricing rules (so a provider's dashboard
 *    and revenue add up), and never beyond an activity's capacity on a day;
 *  - every one of them PAID: a payment that succeeded, for the full total, through the mock gateway;
 *  - status "completed" for days before today, "confirmed" for today and later (a trip is not completed in advance);
 *  - booked by active customers, never by the provider themselves.
 *
 * "At least 50" counts what is already there: the confirmed and completed reservations of the provider in the
 * month that have a succeeded payment, however they were created. So it only adds the shortfall, running it again
 * adds nothing, and raising PROVIDER_MONTH_MINIMUM adds only the difference.
 *
 * An active provider with nothing bookable (no published activity with a price and a departure) gets one small published
 * activity first, "<business> Day Trip": daily departures, 40 places, per-person prices. Without it a provider like that
 * could never reach the minimum. In the demo data that is a third of the approved providers.
 *
 * It does not follow BULK_SEED_SCALE: the minimum is a requirement, not a volume. Everything is deterministic for a
 * given month and seed. It refuses to run in production.
 *
 * Volume: at least 50 reservations, 50 payments and their capacity locks per active provider.
 * Prerequisites: ProviderSeeder (or BulkProviderSeeder), CustomerSeeder (or BulkCustomerSeeder), CategorySeeder.
 */
class ProviderMonthlyReservationSeeder extends BulkSeeder
{
    use PricesReservations;

    private const LIVE = ['confirmed', 'completed', 'pending'];

    private int $reservationsAdded = 0;

    private int $providersToppedUp = 0;

    private int $activitiesCreated = 0;

    protected function seed(): void
    {
        $minimum = max(1, (int) config('seeding.provider_month_minimum'));

        $this->needs('providers', 'ProviderSeeder (or BulkProviderSeeder)', fn ($q) => $q->where('verification_status', 'verified'));
        $this->needs('customers', 'CustomerSeeder (or BulkCustomerSeeder)');
        $this->needs('categories', 'CategorySeeder', fn ($q) => $q->where('status', 'active'));

        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')
            ->whereNull('users.deleted_at')->where('users.status', 'active')
            ->orderBy('customers.id')->get(['customers.id', 'customers.user_id'])->all();

        if ($customers === []) {
            throw new RuntimeException('There is no active customer to make the bookings. Run CustomerSeeder (or BulkCustomerSeeder) first.');
        }

        $providers = $this->activeProviders();

        foreach ($providers as $provider) {
            $this->topUp($provider, $minimum, $customers);
        }

        $this->line(sprintf(
            '%d active provider(s); %d needed bookings (%d reservations added, %d demo activit%s created) for %s.',
            count($providers),
            $this->providersToppedUp,
            $this->reservationsAdded,
            $this->activitiesCreated,
            $this->activitiesCreated === 1 ? 'y' : 'ies',
            $this->now->format('F Y')
        ));
    }

    /** Approved providers whose account is active. */
    private function activeProviders(): array
    {
        return DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.verification_status', 'verified')->whereNull('p.deleted_at')
            ->where('u.status', 'active')->whereNull('u.deleted_at')
            ->orderBy('p.id')
            ->get(['p.id', 'p.user_id', 'p.business_name', 'p.city', 'p.area', 'p.lat', 'p.lng'])->all();
    }

    private function monthStart(): Carbon
    {
        return $this->now->copy()->startOfMonth();
    }

    private function monthEnd(): Carbon
    {
        return $this->now->copy()->endOfMonth()->startOfDay();
    }

    /** Confirmed or completed reservations of the provider in the month that were actually paid. */
    private function paidThisMonth(int $providerId): int
    {
        return DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('a.provider_id', $providerId)->whereNull('r.deleted_at')
            ->whereIn('r.status', ['confirmed', 'completed'])
            ->whereBetween('r.reservation_date', [$this->monthStart()->toDateString(), $this->monthEnd()->toDateString()])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('payments as p')->whereColumn('p.reservation_id', 'r.id')->where('p.status', 'succeeded'))
            ->count();
    }

    private function topUp(object $provider, int $minimum, array $customers): void
    {
        $existing = $this->paidThisMonth($provider->id);

        if ($existing >= $minimum) {
            return;
        }

        $inventory = $this->inventory($provider->id);

        if ($inventory === []) {
            $this->createInventory($provider);
            $inventory = $this->inventory($provider->id);
        }

        if ($inventory === []) {
            throw new RuntimeException("{$provider->business_name} has nothing that can be booked and a bookable activity could not be created for it.");
        }

        $need = $minimum - $existing;
        $month = $this->now->format('Ym');
        $load = $this->existingLoad($provider->id);
        $weekdays = $this->datesByWeekday();

        // Fixed slots, so a re-run recognises its own earlier reservations and only takes the next free ones.
        $slots = array_map(fn (int $k) => $this->uuid('provider-month', "{$provider->id}-{$month}-{$k}"), range(0, $existing + $need + 60));
        $taken = array_flip(DB::table('reservations')->whereIn('uuid', $slots)->pluck('uuid')->all());

        $rows = [];
        $locks = [];

        foreach ($slots as $uuid) {
            if (count($rows) >= $need) {
                break;
            }
            if (isset($taken[$uuid])) {
                continue;
            }

            [$row, $lock] = $this->place($uuid, $provider, $inventory, $customers, $weekdays, $load);
            $rows[] = $row;
            $locks[$lock['activity_booking_option_id'].'|'.$lock['schedule_period_id'].'|'.$lock['reservation_date']] = $lock;
        }

        $this->insert('reservations', $rows);

        $ids = DB::table('reservations')->whereIn('uuid', array_column($rows, 'uuid'))->pluck('id', 'uuid');
        $this->insert('payments', array_map(fn (array $row) => $this->payment($ids[$row['uuid']], $row), $rows));
        $this->insert('reservation_capacity_locks', array_values($locks));

        $this->reservationsAdded += count($rows);
        $this->providersToppedUp++;
    }

    /** One paid booking on a real departure, within capacity. */
    private function place(string $uuid, object $provider, array $inventory, array $customers, array $weekdays, array &$load): array
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $option = $inventory[$this->rand(0, count($inventory) - 1)];
            $period = $option->periods[$this->rand(0, count($option->periods) - 1)];
            $dates = $weekdays[(int) $period->day_of_week];
            $date = $dates[$this->rand(0, count($dates) - 1)];

            $price = $this->price($option);
            $key = $option->activity_id.'|'.$date->toDateString();

            if (($load[$key] ?? 0) + $price['size'] > $option->max_participants) {
                continue;
            }

            $customer = $customers[$this->rand(0, count($customers) - 1)];
            if ($customer->user_id === $provider->user_id) {
                continue; // a provider does not book their own trips
            }

            $load[$key] = ($load[$key] ?? 0) + $price['size'];

            return [
                $this->row($uuid, $option, $period, $customer->id, $date, $price),
                ['activity_booking_option_id' => $option->id, 'schedule_period_id' => $period->id, 'reservation_date' => $date->toDateString(),
                    'created_at' => $this->ts($this->now), 'updated_at' => $this->ts($this->now)],
            ];
        }

        throw new RuntimeException("Could not fit another booking for {$provider->business_name} in {$this->now->format('F Y')} without exceeding an activity's capacity.");
    }

    private function row(string $uuid, object $option, object $period, int $customerId, Carbon $date, array $price): array
    {
        // Booked a few days to two weeks before the trip, and at least two hours ago (so the confirmation is in the past too).
        $ceiling = $this->now->copy()->subHours(2);
        $created = $date->copy()->subDays($this->rand(1, 14))->subMinutes($this->rand(0, 1439));
        if ($created->greaterThan($ceiling)) {
            $created = $ceiling->copy()->subMinutes($this->rand(0, 2880));
        }

        // Not before the activity existed, when that is possible without booking after the trip itself.
        $floor = Carbon::parse($option->activity_created)->addDay();
        if ($floor->lessThanOrEqualTo($date->copy()->startOfDay()) && $created->lessThan($floor)) {
            $created = $floor->copy();
        }
        $created = $created->greaterThan($ceiling) ? $ceiling->copy() : $created;

        $confirmed = $created->copy()->addMinutes($this->rand(1, 15));
        $past = $date->lessThan($this->now->copy()->startOfDay());

        return [
            'uuid' => $uuid,
            'activity_id' => $option->activity_id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customerId,
            'schedule_period_id' => $period->id,
            'reservation_date' => $date->toDateString(),
            'participants' => $price['participants'],
            'quantity' => $price['quantity'],
            'duration' => $price['duration'],
            // A trip is only completed once its day has passed.
            'status' => $past ? 'completed' : 'confirmed',
            'hold_expires_at' => null,
            'pricing_model' => $option->booking_model,
            'currency' => 'EGP',
            'subtotal' => $price['total'],
            'discount' => 0,
            'tax' => 0,
            'total' => $price['total'],
            'pricing_breakdown' => $price['breakdown'],
            'confirmed_at' => $this->ts($confirmed),
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'deleted_at' => null,
            'created_at' => $this->ts($created),
            'updated_at' => $this->ts($confirmed),
        ];
    }

    /** The succeeded payment for a reservation, for its full total. */
    private function payment(int $reservationId, array $reservation): array
    {
        $mock = 'mock_'.$this->uuid('mock-payment', "{$reservationId}-1");

        return [
            'uuid' => $this->uuid('payment', "{$reservationId}-1"),
            // Unique by construction: the reservation number in base 36 plus the attempt number.
            'reference_id' => 'PAY-'.strtoupper(str_pad(base_convert((string) $reservationId, 10, 36), 6, '0', STR_PAD_LEFT)).'1',
            'reservation_id' => $reservationId,
            'gateway' => 'mock',
            'payment_id' => $mock,
            'provider_reference' => $mock,
            'amount' => $reservation['total'],
            'currency' => $reservation['currency'],
            'status' => 'succeeded',
            'failure_reason' => null,
            'refunded_amount' => 0,
            'paid_at' => $reservation['confirmed_at'],
            'raw_payload' => json_encode(['simulate' => 'sync_success', 'amount' => $reservation['total']]),
            'created_at' => $reservation['confirmed_at'],
            'updated_at' => $reservation['confirmed_at'],
        ];
    }

    // --- What can be booked ------------------------------------------------------------------------------

    /** @return list<object> options of the provider's published activities that can really be booked, with their prices and departures */
    private function inventory(int $providerId): array
    {
        $options = DB::table('activity_booking_options as o')->join('activities as a', 'a.id', '=', 'o.activity_id')
            ->where('a.provider_id', $providerId)->where('a.status', 'published')->whereNull('a.deleted_at')
            ->where('o.is_active', 1)->whereNull('o.deleted_at')->orderBy('o.id')
            ->get(['o.id', 'o.activity_id', 'o.booking_model', 'o.min_quantity', 'o.max_quantity', 'o.min_duration', 'o.max_duration', 'o.duration_unit',
                'a.max_participants', 'a.created_at as activity_created']);

        if ($options->isEmpty()) {
            return [];
        }

        $rules = [];
        foreach (DB::table('activity_pricing_rules')->whereIn('activity_booking_option_id', $options->pluck('id'))->where('is_active', 1)->whereNull('schedule_period_id')
            ->get(['activity_booking_option_id', 'participant_type', 'price']) as $rule) {
            $rules[$rule->activity_booking_option_id][$rule->participant_type ?? 'flat'] = (float) $rule->price;
        }

        $periods = [];
        foreach (DB::table('activity_schedule_periods')->whereIn('activity_id', $options->pluck('activity_id')->unique())->where('status', 'active')->whereNull('deleted_at')
            ->get(['id', 'activity_id', 'day_of_week']) as $period) {
            $periods[$period->activity_id][] = $period;
        }

        $inventory = [];
        foreach ($options as $option) {
            $priced = $option->booking_model === 'per_person' ? isset($rules[$option->id]['adult']) : isset($rules[$option->id]['flat']);

            if ($priced && ! empty($periods[$option->activity_id])) {
                $option->rules = $rules[$option->id];
                $option->periods = $periods[$option->activity_id];
                $inventory[] = $option;
            }
        }

        return $inventory;
    }

    /** What is already booked per activity and day (people), so adding more never overbooks. */
    private function existingLoad(int $providerId): array
    {
        $load = [];

        foreach (DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')
            ->where('a.provider_id', $providerId)->whereIn('r.status', self::LIVE)->whereNull('r.deleted_at')
            ->whereBetween('r.reservation_date', [$this->monthStart()->toDateString(), $this->monthEnd()->toDateString()])
            ->get(['r.activity_id', 'r.reservation_date', 'r.participants', 'r.quantity', 'o.booking_model']) as $r) {
            $key = $r->activity_id.'|'.$r->reservation_date;
            $load[$key] = ($load[$key] ?? 0) + match ($r->booking_model) {
                'per_person' => array_sum(json_decode($r->participants, true) ?: [1]),
                'per_unit' => (int) $r->quantity,
                default => 4,
            };
        }

        return $load;
    }

    /** @return array<int, list<Carbon>> the days of the current month, by weekday (0 = Sunday, as the app stores it) */
    private function datesByWeekday(): array
    {
        $dates = [];

        for ($day = $this->monthStart(); $day->lessThanOrEqualTo($this->monthEnd()); $day = $day->copy()->addDay()) {
            $dates[$day->dayOfWeek][] = $day->copy();
        }

        return $dates;
    }

    /**
     * A small, complete, published activity for a provider that has nothing to book: daily departures, 40 places, per-person
     * prices (adult 300, child 150 EGP). Created once; the same one is found again on a re-run.
     */
    private function createInventory(object $provider): void
    {
        $title = "{$provider->business_name} Day Trip";
        $uuid = $this->uuid('provider-month-activity', $provider->id);
        $stamp = $this->ts($this->monthStart()->subDays(5));

        $this->insert('activities', [[
            'uuid' => $uuid,
            'provider_id' => $provider->id,
            'category_id' => DB::table('categories')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->value('id'),
            'title' => $title,
            'slug' => Str::slug($title).'-pm'.$provider->id,
            'description' => "A relaxed day out with {$provider->business_name}: small groups, all safety equipment included.",
            'location' => trim(($provider->area ?? '').', '.($provider->city ?? ''), ', '),
            'meeting_point' => 'Marina entrance',
            'lat' => $provider->lat,
            'lng' => $provider->lng,
            'duration_minutes' => 180,
            'min_participants' => 1,
            'max_participants' => 40,
            'average_rating' => 0,
            'reviews_count' => 0,
            'min_age' => null,
            'status' => 'published',
            'rejection_reason' => null,
            'published_at' => $stamp,
            'deleted_at' => null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]]);

        $activityId = DB::table('activities')->where('uuid', $uuid)->value('id');

        $this->insert('activity_images', [[
            'uuid' => $this->uuid('provider-month-image', $provider->id), 'activity_id' => $activityId, 'path' => "activities/demo/{$activityId}-0.jpg",
            'sort_order' => 0, 'is_cover' => 1, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]]);

        $this->insert('activity_schedule_periods', array_map(fn (int $day) => [
            'uuid' => $this->uuid('provider-month-schedule', "{$provider->id}-{$day}"), 'activity_id' => $activityId, 'day_of_week' => $day,
            'start_time' => '09:00', 'end_time' => '12:00', 'crosses_midnight' => 0, 'instructions' => null, 'status' => 'active',
            'sort_order' => $day, 'deleted_at' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
        ], range(0, 6)));

        $optionUuid = $this->uuid('provider-month-option', $provider->id);
        $this->insert('activity_booking_options', [[
            'uuid' => $optionUuid, 'activity_id' => $activityId, 'name' => 'Standard', 'slug' => 'standard', 'description' => null, 'booking_model' => 'per_person',
            'min_participants' => null, 'max_participants' => null, 'min_quantity' => null, 'max_quantity' => null, 'min_duration' => null, 'max_duration' => null,
            'duration_unit' => null, 'is_active' => 1, 'is_default' => 1, 'sort_order' => 0, 'deleted_at' => null, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]]);

        $optionId = DB::table('activity_booking_options')->where('uuid', $optionUuid)->value('id');

        $this->insert('activity_pricing_rules', array_map(fn (string $type, int $price) => [
            'uuid' => $this->uuid('provider-month-rule', "{$provider->id}-{$type}"), 'activity_booking_option_id' => $optionId, 'participant_type' => $type,
            'price' => $price, 'min_quantity' => null, 'max_quantity' => null, 'schedule_period_id' => null, 'currency' => 'EGP', 'is_active' => 1,
            'created_at' => $stamp, 'updated_at' => $stamp,
        ], ['adult', 'child'], [300, 150]));

        $this->activitiesCreated++;
    }
}
