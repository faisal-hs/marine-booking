<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Database\Seeders\Concerns\PricesReservations;
use Illuminate\Support\Facades\DB;

/**
 * Bookings, built the way the app builds them, not just filled in:
 *  - they use a REAL active option of a published activity, a real departure of that activity and a date that falls
 *    on that departure's weekday;
 *  - the price is computed from the option's pricing rules, and the stored breakdown adds up to the total;
 *  - a day never exceeds the activity's capacity (every bulk activity holds at least 12);
 *  - completed bookings are in the past, confirmed ones today or later; pending ones still hold their slot.
 *
 * Mix: 38% completed, 30% confirmed, 15% cancelled (a good share of them after being paid), 16% expired,
 * 1% pending. Popular activities are booked far more than the rest.
 *
 * Volume at scale 1: 150,000.
 * Prerequisites: BulkActivityPricingRuleSeeder, BulkActivitySchedulePeriodSeeder, BulkCustomerSeeder.
 */
class BulkReservationSeeder extends BulkSeeder
{
    use PricesReservations;

    private const LIVE = ['confirmed', 'completed', 'pending'];

    protected function seed(): void
    {
        $this->needs('activity_pricing_rules', 'BulkActivityPricingRuleSeeder');
        $this->needs('customers', 'BulkCustomerSeeder');

        $pool = $this->pool();
        if ($pool === []) {
            $this->line('No published bulk activity has a priced option yet: nothing to book.');

            return;
        }

        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')
            ->whereNull('users.deleted_at')->where('users.status', 'active')->orderBy('customers.id')->pluck('customers.id')->all();

        $this->insert('reservations', $this->rows($this->scaled(150000), $pool, $customers, $this->existingLoad()));
    }

    /** @return list<object> options that can really be booked, each carrying its rules and departures */
    private function pool(): array
    {
        $rules = [];
        foreach (DB::table('activity_pricing_rules')->where('is_active', 1)->whereNull('schedule_period_id')->get(['activity_booking_option_id', 'participant_type', 'price']) as $rule) {
            $rules[$rule->activity_booking_option_id][$rule->participant_type ?? 'flat'] = (float) $rule->price;
        }

        $periods = [];
        foreach (DB::table('activity_schedule_periods')->where('status', 'active')->whereNull('deleted_at')->get(['id', 'activity_id', 'day_of_week']) as $period) {
            $periods[$period->activity_id][] = $period;
        }

        $pool = [];
        $options = DB::table('activity_booking_options as o')
            ->join('activities as a', 'a.id', '=', 'o.activity_id')
            ->where('a.slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%')->where('a.status', 'published')->whereNull('a.deleted_at')
            ->where('o.is_active', 1)->whereNull('o.deleted_at')
            ->orderBy('o.id')
            ->get(['o.id', 'o.activity_id', 'o.booking_model', 'o.min_quantity', 'o.max_quantity', 'o.min_duration', 'o.max_duration', 'o.duration_unit', 'a.max_participants', 'a.created_at as activity_created']);

        foreach ($options as $option) {
            if (isset($rules[$option->id])) {
                $option->rules = $rules[$option->id];
                $option->periods = $periods[$option->activity_id] ?? [];
                $pool[] = $option;
            }
        }

        return $pool;
    }

    /** What is already booked per activity and day (an upper estimate), so adding more never overbooks. */
    private function existingLoad(): array
    {
        $load = [];
        foreach (DB::table('reservations')->whereIn('status', self::LIVE)->groupBy('activity_id', 'reservation_date')
            ->selectRaw('activity_id, reservation_date, count(*) as c')->get() as $row) {
            $load[$row->activity_id.'|'.$row->reservation_date] = $row->c * 4;
        }

        return $load;
    }

    private function rows(int $count, array $pool, array $customers, array $load): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            $option = $pool[$this->skewed(count($pool))];
            $status = $this->weighted(['completed' => 38, 'confirmed' => 30, 'cancelled' => 15, 'expired' => 16, 'pending' => 1]);
            $period = $option->periods ? $this->pick($option->periods) : null;
            $price = $this->price($option);
            $date = $this->date($status, $period?->day_of_week);

            // Live bookings must fit the day; if the day is full, try the same weekday on another week, else it did not happen.
            if (in_array($status, self::LIVE, true)) {
                $placed = false;
                for ($try = 0; $try < 5 && ! $placed; $try++) {
                    $key = $option->activity_id.'|'.$date->toDateString();
                    if (($load[$key] ?? 0) + $price['size'] <= $option->max_participants) {
                        $load[$key] = ($load[$key] ?? 0) + $price['size'];
                        $placed = true;
                    } else {
                        $date = $status === 'completed' ? $date->copy()->subWeek() : $date->copy()->addWeek();
                    }
                }
                $status = $placed ? $status : 'cancelled';
            }

            yield $this->row($i, $option, $period, $customers[$this->rand(0, count($customers) - 1)], $status, $date, $price);
        }
    }

    /** The day of the trip, on the departure's weekday when there is one. */
    private function date(string $status, ?int $weekday): Carbon
    {
        $offset = match ($status) {
            'completed' => -$this->rand(1, 540),
            'confirmed' => $this->rand(0, 150),
            'cancelled' => $this->chance(50) ? -$this->rand(1, 400) : $this->rand(1, 120),
            'expired' => $this->rand(-120, 30),
            default => $this->rand(7, 90), // pending
        };

        $date = $this->now->copy()->startOfDay()->addDays($offset);

        if ($weekday !== null) {
            $date->addDays(($weekday - $date->dayOfWeek + 7) % 7);
        }

        // Aligning to the weekday may have crossed today: put it back on the right side.
        if ($status === 'completed' && $date->greaterThanOrEqualTo($this->now->copy()->startOfDay())) {
            $date->subWeek();
        }
        if (in_array($status, ['confirmed', 'pending'], true) && $date->lessThan($this->now->copy()->startOfDay())) {
            $date->addWeek();
        }

        return $date;
    }

    private function row(int $i, object $option, ?object $period, int $customerId, string $status, Carbon $date, array $price): array
    {
        $floor = Carbon::parse($option->activity_created)->addDay();
        $created = $status === 'pending'
            ? $this->now->copy()->subMinutes(2)
            : $date->copy()->subDays($this->rand(1, 30))->subMinutes($this->rand(0, 1439));

        // Booked at least two hours ago, so the confirmation a few minutes later and a 15-minute hold are both in the past.
        // (Activities are at least three days old, so this never goes back before the activity existed.)
        $ceiling = $this->now->copy()->subHours(2);
        if ($status !== 'pending' && $created->greaterThan($ceiling)) {
            $created = $ceiling->copy()->subMinutes($this->rand(0, 2880));
        }
        $created = $created->lessThan($floor) ? $floor->copy() : $created;

        $confirmed = null;
        $cancelled = null;
        $reason = null;
        $hold = null;

        if (in_array($status, ['confirmed', 'completed'], true)) {
            $confirmed = $created->copy()->addMinutes($this->rand(1, 15));
        } elseif ($status === 'cancelled') {
            // Many were paid (confirmed) before the guest cancelled.
            $confirmed = $this->chance(55) ? $created->copy()->addMinutes($this->rand(1, 15)) : null;
            $cancelled = ($confirmed ?? $created)->copy()->addHours($this->rand(1, 240));
            $cancelled = $cancelled->greaterThan($this->now) ? $this->now->copy() : $cancelled;
            $reason = $this->pick(['Change of plans', 'Bad weather forecast', 'Found a better price', 'Someone in the group is ill', 'Booked twice by mistake', null]);
        } elseif ($status === 'expired') {
            $hold = $created->copy()->addMinutes(15);
        } else {
            $hold = $this->now->copy()->addMinutes(13);
        }

        return [
            'uuid' => $this->uuid('reservation', $i),
            'activity_id' => $option->activity_id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customerId,
            'schedule_period_id' => $period?->id,
            'reservation_date' => $date->toDateString(),
            'participants' => $price['participants'],
            'quantity' => $price['quantity'],
            'duration' => $price['duration'],
            'status' => $status,
            'hold_expires_at' => $hold ? $this->ts($hold) : null,
            'pricing_model' => $option->booking_model,
            'currency' => 'EGP',
            'subtotal' => $price['total'],
            'discount' => 0,
            'tax' => 0,
            'total' => $price['total'],
            'pricing_breakdown' => $price['breakdown'],
            'confirmed_at' => $confirmed ? $this->ts($confirmed) : null,
            'cancelled_at' => $cancelled ? $this->ts($cancelled) : null,
            'cancellation_reason' => $reason,
            'deleted_at' => null,
            'created_at' => $this->ts($created),
            'updated_at' => $this->ts($cancelled ?? $confirmed ?? $created),
        ];
    }
}
