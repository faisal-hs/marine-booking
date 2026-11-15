<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Database\Seeders\Concerns\PricesReservations;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for GET /provider/analytics, for every published activity: page views by source, bookings by source (some kept, some cancelled, some that
 * expired), the revenue of the trips that took place, and reviews with replies. Run it on top of the demo data:
 *
 *     php artisan db:seed --class=ProviderAnalyticsDemoSeeder
 *
 * What it makes, for each published activity of a verified provider (the bookings and reviews only for those that have a priced booking option and
 * a schedule, because a booking needs a real departure and price):
 *
 *  - PAGE VIEWS, every day from 62 days before the 1st of this month to today (so at least 60 days, whatever day of the month it is), so the current month
 *    AND the default period (the last 30 days) both have a period before them to be compared with: the "change" figures are not empty. Each activity has its own
 *    popularity, trend (some are growing, some falling) and mix of sources, so the activities differ from one another and the top-activities list
 *    has an order. Fridays and Saturdays are busier.
 *  - BOOKINGS made on those days, about 2 to 9 in every hundred views (each activity has its own rate): about 82% are kept (paid; completed once the
 *    trip has taken place, confirmed before), 10% cancelled and refunded, 8% expired unpaid. Each has a source (direct, category, recommendations,
 *    social), a payment, and its place on a real departure of the activity. Trips are never over the activity's capacity.
 *  - REVIEWS on about three in ten of the completed trips (mostly good, as real ones are), and replies from the provider to about half to
 *    nine tenths of them (each provider answers a different share), so the response rate is not 0 or 100.
 *
 * Every figure comes from the seeder's own random engine, started afresh for each activity and day, so it is the same on every run and does not
 * depend on how much was seeded before. Running it again adds nothing for the days already seeded, and only the new days when run on a later day: the start
 * is fixed for the month, so building it up day by day gives exactly what one run at the end would. Today is generated whole but only what has already
 * happened is written, so a run later in the same day adds the hours that have passed since, and nothing else.
 * A booking that will be cancelled is written once the cancellation has happened, and a trip is completed (and reviewed, and the review replied to) once its
 * time has come, so a later run also brings those up to date.
 *
 * Because the demo activities are created at the moment they are seeded, an activity that is newer than the data is given an earlier creation (and
 * publication) date, so nothing is viewed, booked or reviewed before the activity existed. Nothing else about an activity is changed.
 *
 * Volume at scale 1 (the demo data): 36 published activities, 26 of them bookable, so about 35,000 page views, 1,350 bookings and 190 reviews.
 * Prerequisites: DatabaseSeeder (providers, customers, activities). An activity that cannot be booked gets page views only; a provider with nothing bookable at all
 * gets no bookings: run ProviderMonthlyReservationSeeder first, which makes a bookable activity for every active provider.
 */
class ProviderAnalyticsDemoSeeder extends BulkSeeder
{
    use PricesReservations;

    /** Days of history before the 1st of the month the data starts at. */
    private const DAYS_BEFORE_MONTH = 62;

    private const KEY_PREFIX = 'analytics-demo-';

    private const SOURCES = ['direct' => 35, 'category' => 25, 'recommendations' => 25, 'social' => 15];

    private const COMMENTS = ['Amazing experience, the crew was very professional.', 'A lovely day on the water. Would book again.', 'Great value and very well organised.',
        'The reef was beautiful and the guides were friendly.', 'Good trip, a little crowded but worth it.', 'Safe, clean and exactly as described.', 'The kids loved it.',
        'Not what I expected, the boat was late.', 'A bit pricey for what it was.', null];

    private const REPLIES = ['Thank you for your kind words, we hope to see you again soon!', 'We are glad you enjoyed it. See you next time.',
        'Thanks for the feedback, we are working on it.', 'We are sorry it was not perfect, and we have passed your comments to the crew.'];

    private const CANCEL_REASONS = ['Change of plans', 'Found a different date', 'Someone in the group fell ill', 'Weather forecast'];

    private int $views = 0;

    private int $bookings = 0;

    private int $reviews = 0;

    private int $replies = 0;

    protected function seed(): void
    {
        $this->needs('providers', 'ProviderSeeder', fn ($q) => $q->where('verification_status', 'verified'));
        $this->needs('customers', 'CustomerSeeder');
        $this->needs('activity_booking_options', 'ActivitySeeder (or ProviderMonthlyReservationSeeder, which makes a bookable activity for every active provider)');

        $today = $this->now->copy()->startOfDay();
        // A fixed start, 62 days before the 1st of this month: it depends on the month only, never on today, so a run on a later day sees exactly the same
        // earlier days (and the same places taken on every departure) as the run before it. It reaches back at least 60 days from any day of the month, and always
        // past the 31 days before the 1st that a month-to-date period is compared with.
        $first = $this->now->copy()->startOfMonth()->subDays(self::DAYS_BEFORE_MONTH);

        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->whereNull('users.deleted_at')->where('users.status', 'active')
            ->orderBy('customers.id')->get(['customers.id', 'customers.user_id'])->all();

        $activities = $this->activities();
        $touched = [];

        foreach ($activities as $activity) {
            $this->predate($activity, $first);
            $profile = $this->profile($activity);
            $load = $activity->bookable ? $this->existingLoad($activity, $first) : [];
            $this->completePastTrips($activity, $today);
            $uuids = [];

            $days = $this->days($first, $today);

            foreach ($days as $index => $day) {
                $this->startRandom((crc32("analytics|{$activity->id}|{$day->toDateString()}") ^ (int) config('seeding.seed')) & 0x7FFFFFFF);
                $views = $this->viewsOn($day, $this->progress($day), $profile);
                $this->views += $this->insert('activity_views', $this->viewRows($activity, $day, $views, $profile));
                $uuids = array_merge($uuids, ! $activity->bookable ? [] : $this->bookDay($activity, $day, $today, $views, $profile, $customers, $load));
            }

            if ($this->reviewCompleted($activity, $uuids, $profile)) {
                $touched[] = $activity->id;
            }
        }

        $this->refreshRatings($touched);

        $this->line(sprintf('%d activit%s: %d page views, %d bookings, %d reviews (%d answered), from %s to %s.', count($activities), count($activities) === 1 ? 'y' : 'ies',
            $this->views, $this->bookings, $this->reviews, $this->replies, $first->toDateString(), $today->toDateString()));
    }

    // --- What to seed -------------------------------------------------------------------------------------

    /** @return list<object> every published activity of a verified, active provider; the ones that can be booked also have their default priced option and schedule */
    private function activities(): array
    {
        $options = DB::table('activity_booking_options as o')->join('activities as a', 'a.id', '=', 'o.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')->where('a.status', 'published')->whereNull('a.deleted_at')->where('p.verification_status', 'verified')->whereNull('p.deleted_at')
            ->where('u.status', 'active')->whereNull('u.deleted_at')->where('o.is_active', 1)->whereNull('o.deleted_at')
            ->orderBy('a.id')->orderByDesc('o.is_default')->orderBy('o.sort_order')->orderBy('o.id')
            ->get(['o.id as option_id', 'o.booking_model', 'o.min_quantity', 'o.max_quantity', 'o.min_duration', 'o.max_duration', 'o.duration_unit', 'o.max_participants as option_max_participants', 'a.id', 'a.uuid',
                'a.provider_id', 'p.user_id as provider_user_id', 'a.max_participants', 'a.created_at']);

        $rules = [];
        foreach (DB::table('activity_pricing_rules')->whereIn('activity_booking_option_id', $options->pluck('option_id'))->where('is_active', 1)->whereNull('schedule_period_id')
            ->get(['activity_booking_option_id', 'participant_type', 'price']) as $rule) {
            $rules[$rule->activity_booking_option_id][$rule->participant_type ?? 'flat'] = (float) $rule->price;
        }

        $periods = [];
        foreach (DB::table('activity_schedule_periods')->whereIn('activity_id', $options->pluck('id')->unique())->where('status', 'active')->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'activity_id', 'day_of_week']) as $period) {
            $periods[$period->activity_id][(int) $period->day_of_week][] = $period;
        }

        $seen = [];
        $list = [];
        foreach ($options as $option) {
            if (isset($seen[$option->id])) {
                continue; // an activity's first option is its default one
            }
            $priced = $option->booking_model === 'per_person' ? isset($rules[$option->option_id]['adult']) : isset($rules[$option->option_id]['flat']);

            if ($priced && ! empty($periods[$option->id])) {
                $seen[$option->id] = true;
                $option->id = (int) $option->id;
                $option->rules = $rules[$option->option_id];
                $option->periods = $periods[$option->id];
                $option->bookable = true;
                $list[] = $option;
            }
        }

        // The published activities that cannot be booked yet (no priced option, or no schedule) still have visitors: they get page views, and no bookings.
        foreach (DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('users as u', 'u.id', '=', 'p.user_id')->where('a.status', 'published')
            ->whereNull('a.deleted_at')->where('p.verification_status', 'verified')->whereNull('p.deleted_at')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->whereNotIn('a.id', array_keys($seen))->orderBy('a.id')->get(['a.id', 'a.uuid', 'a.provider_id', 'p.user_id as provider_user_id', 'a.max_participants', 'a.created_at']) as $viewed) {
            $viewed->id = (int) $viewed->id;
            $viewed->bookable = false;
            $list[] = $viewed;
        }

        usort($list, fn ($a, $b) => $a->id <=> $b->id);

        return $list;
    }

    /** An activity that is newer than the data is made older than it, so nothing happens to it before it existed. */
    private function predate(object $activity, Carbon $first): void
    {
        $floor = $first->copy()->subDay()->setTime(9, 0);

        if (Carbon::parse($activity->created_at)->greaterThan($floor)) {
            DB::table('activities')->where('id', $activity->id)->update(['created_at' => $this->ts($floor)]);
            // Published an hour after it was created, as one that went live at once is.
            DB::table('activities')->where('id', $activity->id)->whereNotNull('published_at')->update(['published_at' => $this->ts($floor->copy()->addHour())]);
        }
    }

    /** @return array{popularity: float, trend: float, conversion: float, quality: int, answers: float, sources: array<string, float>} */
    private function profile(object $activity): array
    {
        $roll = fn (string $what, int $min, int $max) => $min + (crc32("{$what}|{$activity->uuid}|".config('seeding.seed')) % ($max - $min + 1));

        $sources = [];
        foreach (self::SOURCES as $source => $weight) {
            $sources[$source] = $weight * ($roll("source-{$source}", 60, 160) / 100);
        }

        return [
            'popularity' => $roll('popularity', 35, 180) / 100,
            'trend' => ($roll('trend', 0, 100) - 30) / 100,     // from -0.3 (falling) to +0.7 (growing)
            'conversion' => $roll('conversion', 20, 90) / 10,   // in every hundred views
            'quality' => $roll('quality', 0, 2),                // 0 = poor, 1 = fair, 2 = very good
            'answers' => $roll('answers', 50, 90) / 100,        // the share of reviews the provider answers
            'sources' => $sources,
        ];
    }

    /** @return list<Carbon> */
    private function days(Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $days[] = $day->copy();
        }

        return $days;
    }

    // --- Page views -----------------------------------------------------------------------------------------

    /**
     * How far along a day is, 0 to 1, in a span that starts 62 days before the 1st of this month and lasts 93 days. It depends on the day and the MONTH only,
     * never on today, so the window sliding forward a day does not change what an earlier day got.
     */
    private function progress(Carbon $day): float
    {
        $start = $this->now->copy()->startOfMonth()->subDays(62);

        return max(0.0, min(1.0, $start->diffInDays($day->copy()->startOfDay(), false) / 93));
    }

    /** @param  float  $progress  0 at the start of the span, 1 at its end: a growing activity gets busier along it, a falling one quieter */
    private function viewsOn(Carbon $day, float $progress, array $profile): int
    {
        $weekday = [0 => 0.9, 1 => 0.85, 2 => 1.0, 3 => 1.0, 4 => 1.1, 5 => 1.35, 6 => 1.5][$day->dayOfWeek];
        $trend = 1 + $profile['trend'] * ($progress - 0.5) * 2;

        return max(0, (int) round(14 * $weekday * $profile['popularity'] * $trend * ($this->rand(70, 130) / 100)));
    }

    /**
     * The views of one day, whole: today's too. Only those that have already happened are returned, so a later run the same day adds the hours that have passed
     * since and nothing else, and the day always comes out the same.
     *
     * @return list<array<string, mixed>>
     */
    private function viewRows(object $activity, Carbon $day, int $views, array $profile): array
    {
        $rows = [];

        for ($n = 1; $n <= $views; $n++) {
            $at = $day->copy()->addMinutes($this->rand(0, 1439));
            $source = $this->weighted($profile['sources']);

            if ($at->lessThan($this->now)) {
                $rows[] = ['activity_id' => $activity->id, 'visitor_hash' => sha1("analytics-demo|{$activity->id}|{$day->toDateString()}|{$n}"), 'viewed_on' => $day->toDateString(),
                    'source' => $source, 'created_at' => $this->ts($at)];
            }
        }

        return $rows;
    }

    // --- Bookings --------------------------------------------------------------------------------------------

    /**
     * The bookings made on one day. Every draw is made whether or not the booking already exists, so the day always comes out the same.
     *
     * @return list<string> the uuids of the bookings of the day
     */
    private function bookDay(object $activity, Carbon $day, Carbon $today, int $views, array $profile, array $customers, array &$load): array
    {
        $expected = $views * $profile['conversion'] / 100;
        $count = (int) floor($expected) + ($this->chance(($expected - floor($expected)) * 100) ? 1 : 0);
        $rows = [];
        $locks = [];

        for ($k = 1; $k <= $count; $k++) {
            $row = $this->booking($activity, $day, $today, $k, $profile, $customers, $load);

            if ($row !== null) {
                [$rows[], $lock] = $row;
                if ($lock !== null) {
                    $locks[$lock['activity_booking_option_id'].'|'.$lock['schedule_period_id'].'|'.$lock['reservation_date']] = $lock;
                }
            }
        }

        // The whole day is generated, and every booking counts for capacity, but one is written only once it has happened: created, and then confirmed or lapsed
        // (within 20 minutes), so nothing is dated after now.
        $uuids = array_column($rows, 'uuid');
        $happened = $this->now->copy()->subMinutes(20)->format('Y-m-d H:i:s');
        $passed = $this->now->format('Y-m-d H:i:s');
        $new = array_values(array_filter($rows, fn (array $row) => $row['created_at'] <= $happened && ($row['cancelled_at'] === null || $row['cancelled_at'] <= $passed)
            && ! DB::table('reservations')->where('uuid', $row['uuid'])->exists()));

        $this->bookings += $this->insert('reservations', $new);
        $ids = DB::table('reservations')->whereIn('uuid', array_column($new, 'uuid'))->pluck('id', 'uuid');
        $this->insert('payments', array_values(array_filter(array_map(fn (array $row) => $this->payment($ids[$row['uuid']], $row), $new))));
        $this->insert('reservation_capacity_locks', array_values($locks));

        return $uuids;
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>}|null the reservation and the departure it holds, or null when there is no room or no departure */
    private function booking(object $activity, Carbon $day, Carbon $today, int $k, array $profile, array $customers, array &$load): ?array
    {
        $uuid = $this->uuid('analytics-demo', "{$activity->id}-{$day->toDateString()}-{$k}");
        $created = $day->copy()->addMinutes($this->rand(0, 1439));
        $outcome = $this->weighted(['kept' => 82, 'cancelled' => 10, 'expired' => 8]);
        $source = $this->weighted($profile['sources']);
        $price = $this->price($activity);
        $customer = $customers[$this->rand(0, count($customers) - 1)];

        // The first departure on or after the trip date that the activity actually runs on.
        $trip = $day->copy()->addDays($this->rand(1, 14));
        $period = null;
        for ($t = 0; $t < 14 && $period === null; $t++) {
            $candidate = $trip->copy()->addDays($t);
            if (! empty($activity->periods[$candidate->dayOfWeek])) {
                $period = $activity->periods[$candidate->dayOfWeek][$this->rand(0, count($activity->periods[$candidate->dayOfWeek]) - 1)];
                $trip = $candidate;
            }
        }

        if ($period === null || $customer->user_id === $activity->provider_user_id) {
            return null;
        }

        // Capacity is counted as the booking itself counts it: per departure, the people of a per-person booking against the option's maximum (else the
        // activity's), and the quantity of a per-unit booking against the option's maximum quantity. A flat-priced booking has neither, so it is never limited.
        $holds = $outcome === 'kept';
        $key = "{$activity->option_id}|{$period->id}|{$trip->toDateString()}";
        $people = $price['participants'] ? array_sum(json_decode($price['participants'], true)) : 0;
        $quantity = (int) ($price['quantity'] ?? 0);
        $peopleLimit = $activity->option_max_participants ?? $activity->max_participants;

        if ($holds && (($people > 0 && $peopleLimit !== null && ($load[$key]['p'] ?? 0) + $people > $peopleLimit)
            || ($quantity > 0 && $activity->max_quantity !== null && ($load[$key]['q'] ?? 0) + $quantity > $activity->max_quantity))) {
            return null;
        }
        if ($holds) {
            $load[$key] = ['p' => ($load[$key]['p'] ?? 0) + $people, 'q' => ($load[$key]['q'] ?? 0) + $quantity];
        }

        $confirmed = $created->copy()->addMinutes($this->rand(1, 15));
        // When it is cancelled is decided now, and it is written only once that moment has passed (see bookDay), so it is never cut short by "now".
        $cancelledAt = $outcome === 'cancelled' ? $confirmed->copy()->addMinutes($this->rand(60, 4320)) : null;

        $row = [
            'uuid' => $uuid, 'activity_id' => $activity->id, 'activity_booking_option_id' => $activity->option_id, 'customer_id' => $customer->id, 'schedule_period_id' => $period->id,
            'reservation_date' => $trip->toDateString(), 'participants' => $price['participants'], 'quantity' => $price['quantity'], 'duration' => $price['duration'],
            // A trip is only completed once its day has passed.
            'status' => $outcome === 'kept' ? ($trip->lessThan($today) ? 'completed' : 'confirmed') : $outcome,
            'hold_expires_at' => $outcome === 'expired' ? $this->ts($created->copy()->addMinutes(15)) : null,
            'pricing_model' => $activity->booking_model, 'currency' => config('platform.currency'), 'subtotal' => $price['total'], 'discount' => 0, 'tax' => 0, 'service_fee' => 0,
            'total' => $price['total'], 'pricing_breakdown' => $price['breakdown'], 'payment_method' => 'card', 'source' => $source,
            // Marks the booking as this seeder's own, so a re-run can tell its own bookings from everyone else's when it counts the places that are taken.
            'idempotency_key' => self::KEY_PREFIX.$uuid,
            'confirmed_at' => $outcome === 'expired' ? null : $this->ts($confirmed),
            'cancelled_at' => $cancelledAt ? $this->ts($cancelledAt) : null,
            'cancellation_reason' => $cancelledAt ? $this->pick(self::CANCEL_REASONS) : null,
            'cancellation_source' => $cancelledAt ? 'customer' : null, 'cancelled_by_user_id' => $cancelledAt ? $customer->user_id : null,
            'deleted_at' => null, 'created_at' => $this->ts($created), 'updated_at' => $this->ts($cancelledAt ?? $confirmed),
        ];

        $lock = $holds ? ['activity_booking_option_id' => $activity->option_id, 'schedule_period_id' => $period->id, 'reservation_date' => $trip->toDateString(),
            'created_at' => $this->ts($this->now), 'updated_at' => $this->ts($this->now)] : null;

        return [$row, $lock];
    }

    /** @return array<string, mixed>|null an expired booking was never paid */
    private function payment(int $reservationId, array $reservation): ?array
    {
        if ($reservation['status'] === 'expired') {
            return null;
        }

        $mock = 'mock_'.$this->uuid('analytics-mock-payment', "{$reservation['uuid']}-1");
        $refunded = $reservation['status'] === 'cancelled';

        return [
            'uuid' => $this->uuid('analytics-payment', "{$reservation['uuid']}-1"),
            'reference_id' => 'PAY-'.strtoupper(str_pad(base_convert((string) $reservationId, 10, 36), 6, '0', STR_PAD_LEFT)).'1',
            'reservation_id' => $reservationId, 'gateway' => 'mock', 'payment_id' => $mock, 'provider_reference' => $mock,
            'amount' => $reservation['total'], 'currency' => $reservation['currency'], 'status' => $refunded ? 'refunded' : 'succeeded', 'failure_reason' => null,
            'refunded_amount' => $refunded ? $reservation['total'] : 0, 'paid_at' => $reservation['confirmed_at'],
            'raw_payload' => json_encode(['simulate' => 'sync_success', 'amount' => $reservation['total']]),
            'created_at' => $reservation['confirmed_at'], 'updated_at' => $reservation['updated_at'],
        ];
    }

    /**
     * The places already taken by other bookings, per departure: the same count the booking uses, so a seeded booking never goes over a real one.
     *
     * @return array<string, array{p: int, q: int}>
     */
    private function existingLoad(object $activity, Carbon $from): array
    {
        $load = [];
        foreach (DB::table('reservations')->where('activity_booking_option_id', $activity->option_id)->whereIn('status', ['confirmed', 'completed', 'pending'])->whereNull('deleted_at')
            // Not this seeder's own bookings: it makes them again on every run, in the same order, and counts them as it goes.
            ->where(fn ($q) => $q->whereNull('idempotency_key')->orWhere('idempotency_key', 'not like', self::KEY_PREFIX.'%'))
            ->where('reservation_date', '>=', $from->toDateString())->get(['schedule_period_id', 'reservation_date', 'participants', 'quantity']) as $r) {
            $key = "{$activity->option_id}|{$r->schedule_period_id}|".substr($r->reservation_date, 0, 10);
            $load[$key] = ['p' => ($load[$key]['p'] ?? 0) + array_sum(json_decode($r->participants ?? '[]', true) ?: []), 'q' => ($load[$key]['q'] ?? 0) + (int) $r->quantity];
        }

        return $load;
    }

    /** A trip is completed once its day has passed: the confirmed ones of an earlier run, whose day has gone by since, are completed now. */
    private function completePastTrips(object $activity, Carbon $today): void
    {
        DB::table('reservations')->where('activity_id', $activity->id)->where('idempotency_key', 'like', self::KEY_PREFIX.'%')->where('status', 'confirmed')
            ->where('reservation_date', '<', $today->toDateString())->update(['status' => 'completed']);
    }

    // --- Reviews -------------------------------------------------------------------------------------------------

    /**
     * Reviews on some of the completed trips, and the provider's replies to some of them.
     *
     * @param  list<string>  $uuids  the bookings of the activity that this run covers
     */
    private function reviewCompleted(object $activity, array $uuids, array $profile): bool
    {
        if ($uuids === []) {
            return false;
        }

        $taken = [];
        foreach (DB::table('reviews')->where('activity_id', $activity->id)->pluck('customer_id') as $customerId) {
            $taken[$customerId] = true;
        }

        $made = [];
        foreach (DB::table('reservations')->whereIn('uuid', $uuids)->where('status', 'completed')->orderBy('reservation_date')->orderBy('id')->get(['id', 'uuid', 'customer_id', 'reservation_date']) as $trip) {
            $at = Carbon::parse($trip->reservation_date)->addDay()->setTime(10, 0)->addMinutes(crc32($trip->uuid) % 600);

            if (isset($taken[$trip->customer_id]) || ! $this->stable("analytics-review|{$trip->uuid}", 30)) {
                continue;
            }

            // WHICH trip the customer reviews (a customer reviews an activity once) is decided by the trips alone, never by the clock: it is the first of their
            // trips that is one to review. Only whether that review exists yet depends on the time, so building the data up over days chooses the same trip
            // as one run at the end.
            $taken[$trip->customer_id] = true;

            if ($at->greaterThan($this->now)) {
                continue;
            }

            // Each review is drawn from a stream of its own, so what it says never depends on which other reviews were made before it, in this run or an earlier one.
            $this->startRandom((crc32("analytics-review|{$trip->uuid}") ^ (int) config('seeding.seed')) & 0x7FFFFFFF);
            $made[] = [
                'uuid' => $this->uuid('analytics-review', $trip->uuid), 'customer_id' => $trip->customer_id, 'activity_id' => $activity->id, 'reservation_id' => $trip->id,
                'rating' => (int) $this->weighted([
                    0 => ['5' => 30, '4' => 20, '3' => 20, '2' => 18, '1' => 12],
                    1 => ['5' => 45, '4' => 30, '3' => 15, '2' => 7, '1' => 3],
                    2 => ['5' => 68, '4' => 24, '3' => 6, '2' => 1, '1' => 1],
                ][$profile['quality']]),
                'comment' => $this->pick(self::COMMENTS), 'is_hidden' => 0, 'hidden_reason' => null, 'hidden_at' => null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at),
            ];
        }

        $this->reviews += $this->insert('reviews', $made);

        // The replies are decided for ALL of the seeder's reviews of the activity, not just the ones made now: a reply whose time has come since an earlier run is made now.
        $answered = [];
        $reservationIds = DB::table('reservations')->whereIn('uuid', $uuids)->pluck('id');
        foreach (DB::table('reviews')->whereIn('reservation_id', $reservationIds)->get(['id', 'uuid', 'created_at']) as $review) {
            $at = Carbon::parse($review->created_at)->addMinutes($this->hoursFrom($review->uuid) * 60);

            if ($at->lessThanOrEqualTo($this->now) && $this->stable("analytics-reply|{$review->uuid}", $profile['answers'] * 100)) {
                $answered[] = ['review_id' => $review->id, 'provider_id' => $activity->provider_id, 'text' => self::REPLIES[crc32($review->uuid) % count(self::REPLIES)],
                    'is_active' => 1, 'deleted_at' => null, 'deleted_by_user_id' => null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at)];
            }
        }

        $this->replies += $this->insert('review_replies', $answered);

        return $made !== [];
    }

    /** One to thirty-six hours, the same for a given review every time. */
    private function hoursFrom(string $uuid): int
    {
        return 1 + crc32("hours|{$uuid}") % 36;
    }

    /** The average and the count of every reviewed activity, from its visible reviews, exactly as the app keeps them. */
    private function refreshRatings(array $activityIds): void
    {
        if ($activityIds === []) {
            return;
        }

        foreach (DB::table('reviews')->where('is_hidden', 0)->whereIn('activity_id', $activityIds)->groupBy('activity_id')->selectRaw('activity_id, AVG(rating) AS average, COUNT(*) AS total')->get() as $stats) {
            DB::table('activities')->where('id', $stats->activity_id)->update(['average_rating' => round((float) $stats->average, 2), 'reviews_count' => $stats->total]);
        }
    }
}
