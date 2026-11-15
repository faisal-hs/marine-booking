<?php

namespace Tests\Feature\Seeders;

use App\Models\Provider;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProviderAnalyticsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * ProviderAnalyticsDemoSeeder fills GET /provider/analytics for every published activity. Today is Sunday 15 November 2026, so the data runs from 31 August
 * (62 days before the 1st of November) to today. Each test states one thing that must hold, against the demo data.
 */
class ProviderAnalyticsDemoSeederTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const NOW = '2026-11-15 12:00:00';

    private const OWN = 'analytics-demo-%';

    private const TABLES = ['activity_views', 'reservations', 'payments', 'reviews', 'review_replies', 'reservation_capacity_locks'];

    /** A published activity of a verified provider that cannot be booked yet: it has no priced option and no schedule. The demo data has none. */
    private int $unbookable;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        Storage::fake(config('providers.documents.disk'));
        $this->seed(DatabaseSeeder::class);

        $providerId = DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.verification_status', 'verified')->where('u.status', 'active')->whereNull('u.deleted_at')->orderBy('p.id')->value('p.id');
        $this->unbookable = $this->makeActivity(Provider::find($providerId), 'published')->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function run1(): void
    {
        $this->seed(ProviderAnalyticsDemoSeeder::class);
    }

    private function counts(): array
    {
        return array_combine(self::TABLES, array_map(fn ($t) => DB::table($t)->count(), self::TABLES));
    }

    /**
     * Everything the seeder made, as a short fingerprint of each table (the number of rows and a hash of them all, in a fixed order). Comparing these says
     * whether two runs made exactly the same data, and says it at once: comparing the rows themselves, tens of thousands of them, is very slow to report.
     *
     * @return array<string, string|array<string, int>>
     */
    private function fingerprint(): array
    {
        $tables = [
            'views' => DB::table('activity_views')->orderBy('activity_id')->orderBy('visitor_hash')->orderBy('viewed_on')->select('activity_id', 'visitor_hash', 'viewed_on', 'source', 'created_at'),
            'reservations' => $this->own()->orderBy('uuid')->select('uuid', 'status', 'total', 'reservation_date', 'schedule_period_id', 'source', 'created_at', 'confirmed_at', 'cancelled_at', 'hold_expires_at'),
            'payments' => DB::table('payments')->orderBy('uuid')->select('uuid', 'status', 'amount', 'refunded_amount', 'paid_at'),
            'reviews' => DB::table('reviews')->orderBy('uuid')->select('uuid', 'rating', 'comment', 'created_at'),
            'replies' => DB::table('review_replies')->orderBy('created_at')->orderBy('review_id')->select('text', 'created_at'),
        ];
        $out = ['counts' => $this->counts()];

        foreach ($tables as $name => $query) {
            $hash = hash_init('md5');
            foreach ($query->cursor() as $row) {
                hash_update($hash, json_encode($row));
            }
            $out[$name] = hash_final($hash);
        }

        return $out;
    }

    private function own()
    {
        return DB::table('reservations')->where('idempotency_key', 'like', self::OWN);
    }

    /** @return list<int> published activities of verified, active providers */
    private function eligible(): array
    {
        return DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('a.status', 'published')->whereNull('a.deleted_at')->where('p.verification_status', 'verified')->whereNull('p.deleted_at')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->orderBy('a.id')->pluck('a.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> the eligible ones that can be booked: a priced active option and a schedule */
    private function bookable(): array
    {
        return array_values(array_filter($this->eligible(), fn ($id) => DB::table('activity_booking_options as o')->join('activity_pricing_rules as r', 'r.activity_booking_option_id', '=', 'o.id')
            ->where('o.activity_id', $id)->where('o.is_active', 1)->whereNull('o.deleted_at')->where('r.is_active', 1)->exists()
            && DB::table('activity_schedule_periods')->where('activity_id', $id)->where('status', 'active')->whereNull('deleted_at')->exists()));
    }

    // --- Page views --------------------------------------------------------------------------------------------

    public function test_every_published_activity_gets_page_views_over_the_whole_window_and_nothing_else_does(): void
    {
        $this->run1();

        $viewed = DB::table('activity_views')->distinct()->pluck('activity_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($this->eligible(), $viewed, 'Exactly the published activities of verified providers');
        $this->assertGreaterThan(10, count($viewed));
        $this->assertSame(['2026-08-31', '2026-11-15'], [DB::table('activity_views')->min('viewed_on'), DB::table('activity_views')->max('viewed_on')]);
    }

    public function test_each_activity_has_views_in_this_month_and_in_the_period_before_it_so_the_change_is_never_empty(): void
    {
        $this->run1();

        foreach ($this->eligible() as $id) {
            $views = fn (string $from, string $to) => DB::table('activity_views')->where('activity_id', $id)->whereBetween('viewed_on', [$from, $to])->count();

            $this->assertGreaterThan(0, $views('2026-11-01', '2026-11-15'), "Activity {$id}: this month");
            $this->assertGreaterThan(0, $views('2026-10-17', '2026-10-31'), "Activity {$id}: the 15 days before the month");
            $this->assertGreaterThan(0, $views('2026-09-17', '2026-10-16'), "Activity {$id}: the 30 days before the default period");
        }
    }

    public function test_activities_differ_in_how_popular_they_are_and_in_where_their_visitors_come_from(): void
    {
        $this->run1();
        $totals = DB::table('activity_views')->selectRaw('activity_id, COUNT(*) AS n')->groupBy('activity_id')->pluck('n')->all();

        $this->assertGreaterThan(2, max($totals) / max(1, min($totals)), 'The busiest has well over twice the views of the quietest');
        $this->assertEqualsCanonicalizing(['direct', 'category', 'recommendations', 'social'], DB::table('activity_views')->distinct()->pluck('source')->all());
        $mix = DB::table('activity_views')->selectRaw("activity_id, SUM(source = 'social') * 1.0 / COUNT(*) AS social")->groupBy('activity_id')->pluck('social')->map(fn ($v) => round($v, 2))->unique();
        $this->assertGreaterThan(3, $mix->count(), 'Not every activity has the same mix of sources');
    }

    public function test_fridays_and_saturdays_are_busier_than_mondays(): void
    {
        $this->run1();
        $perDay = DB::table('activity_views')->selectRaw('viewed_on, COUNT(*) AS n')->where('viewed_on', '<', '2026-11-15')->groupBy('viewed_on')->pluck('n', 'viewed_on');
        $by = fn (int $dow) => collect($perDay)->filter(fn ($n, $d) => Carbon::parse($d)->dayOfWeek === $dow)->avg();

        $this->assertGreaterThan($by(1) * 1.4, $by(6));
        $this->assertGreaterThan($by(1) * 1.2, $by(5));
    }

    public function test_every_visitor_is_one_view_a_day_and_nothing_is_from_the_future(): void
    {
        $this->run1();

        $this->assertSame(0, DB::table('activity_views')->selectRaw('COUNT(*) AS n')->groupBy('activity_id', 'visitor_hash', 'viewed_on')->having('n', '>', 1)->get()->count());
        $this->assertSame(0, DB::table('activity_views')->where('created_at', '>', self::NOW)->count());
        $this->assertSame(0, DB::table('activity_views')->whereRaw('substr(created_at, 1, 10) != viewed_on')->count(), 'A view is created on the day it was viewed');
        $this->assertGreaterThan(0, DB::table('activity_views')->where('viewed_on', '2026-11-15')->count(), 'Today has the hours so far');
    }

    // --- Bookings ------------------------------------------------------------------------------------------------

    public function test_only_activities_that_can_be_booked_get_bookings_and_each_of_them_does(): void
    {
        $this->run1();
        $booked = $this->own()->distinct()->pluck('activity_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($this->bookable(), $booked);
        $notBookable = array_diff($this->eligible(), $this->bookable());
        $this->assertSame([$this->unbookable], array_values($notBookable));
        $this->assertSame(0, DB::table('reservations')->whereIn('activity_id', $notBookable)->count());
        $this->assertGreaterThan(0, DB::table('activity_views')->whereIn('activity_id', $notBookable)->count(), 'They still have visitors');
    }

    public function test_bookings_are_kept_cancelled_or_expired_in_realistic_shares_with_the_right_money_trail(): void
    {
        $this->run1();
        $byStatus = $this->own()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $total = $byStatus->sum();

        $this->assertEqualsCanonicalizing(['completed', 'confirmed', 'cancelled', 'expired'], $byStatus->keys()->all());
        $this->assertEqualsWithDelta(82, ($byStatus['completed'] + $byStatus['confirmed']) / $total * 100, 6);
        $this->assertEqualsWithDelta(10, $byStatus['cancelled'] / $total * 100, 5);

        // Kept: paid in full. Cancelled: paid, then refunded in full. Expired: never paid.
        $payment = fn (string $status) => $this->own()->where('reservations.status', $status)->leftJoin('payments', 'payments.reservation_id', '=', 'reservations.id');
        $this->assertSame(0, $payment('completed')->where(fn ($q) => $q->whereNull('payments.id')->orWhere('payments.status', '!=', 'succeeded')->orWhereColumn('payments.amount', '!=', 'reservations.total'))->count());
        $this->assertSame(0, $payment('confirmed')->where(fn ($q) => $q->whereNull('payments.id')->orWhere('payments.status', '!=', 'succeeded'))->count());
        $this->assertSame(0, $payment('cancelled')->where(fn ($q) => $q->whereNull('payments.id')->orWhere('payments.status', '!=', 'refunded')->orWhereColumn('payments.refunded_amount', '!=', 'reservations.total'))->count());
        $this->assertSame(0, $payment('expired')->whereNotNull('payments.id')->count());
    }

    public function test_a_cancelled_booking_says_who_when_and_why_and_an_expired_one_is_a_lapsed_hold(): void
    {
        $this->run1();

        $this->assertSame(0, $this->own()->where('status', 'cancelled')->where(fn ($q) => $q->whereNull('cancelled_at')->orWhereNull('cancellation_reason')->orWhere('cancellation_source', '!=', 'customer')->orWhereNull('cancelled_by_user_id')
            ->orWhereColumn('cancelled_at', '<', 'confirmed_at'))->count());
        $this->assertSame(0, $this->own()->where('status', 'expired')->where(fn ($q) => $q->whereNull('hold_expires_at')->orWhereNotNull('confirmed_at')->orWhere('hold_expires_at', '>', self::NOW))->count());
        $this->assertSame(0, $this->own()->whereNotIn('status', ['cancelled'])->whereNotNull('cancelled_at')->count());
    }

    public function test_a_trip_is_completed_only_once_its_day_has_passed(): void
    {
        $this->run1();

        $this->assertSame(0, $this->own()->where('status', 'completed')->where('reservation_date', '>=', '2026-11-15')->count());
        $this->assertSame(0, $this->own()->where('status', 'confirmed')->where('reservation_date', '<', '2026-11-15')->count());
        $this->assertGreaterThan(0, $this->own()->where('status', 'confirmed')->count(), 'There are trips still to come');
    }

    public function test_a_provider_never_books_their_own_trips(): void
    {
        $this->run1();

        $this->assertSame(0, $this->own()->join('customers as c', 'c.id', '=', 'reservations.customer_id')->join('activities as a', 'a.id', '=', 'reservations.activity_id')
            ->join('providers as p', 'p.id', '=', 'a.provider_id')->whereColumn('c.user_id', 'p.user_id')->count());
    }

    public function test_bookings_have_a_source_and_every_source_is_used(): void
    {
        $this->run1();

        $this->assertEqualsCanonicalizing(['direct', 'category', 'recommendations', 'social'], $this->own()->distinct()->pluck('source')->all());
        $this->assertSame(0, $this->own()->whereNull('source')->count());
    }

    public function test_every_booking_is_on_a_real_departure_of_its_activity_and_never_over_capacity(): void
    {
        $this->run1();

        $this->assertSame(0, $this->own()->join('activity_schedule_periods as s', 's.id', '=', 'reservations.schedule_period_id')
            ->where(fn ($q) => $q->whereColumn('s.activity_id', '!=', 'reservations.activity_id')->orWhereRaw("CAST(strftime('%w', reservations.reservation_date) AS INTEGER) != s.day_of_week"))->count(), 'The period belongs to the activity and runs on that weekday');
        $this->assertSame(0, $this->own()->join('activity_booking_options as o', 'o.id', '=', 'reservations.activity_booking_option_id')->whereColumn('o.activity_id', '!=', 'reservations.activity_id')->count());

        $this->assertNoDepartureOverCapacity();
    }

    /**
     * Capacity is counted as the booking counts it: per departure (option, period and date), people against the option's maximum (else the activity's),
     * quantity against the option's maximum quantity; a flat-priced booking is never limited.
     */
    private function assertNoDepartureOverCapacity(): void
    {
        $load = [];
        foreach (DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->whereIn('r.status', ['confirmed', 'completed'])->get(['r.activity_booking_option_id', 'r.schedule_period_id', 'r.reservation_date', 'r.participants', 'r.quantity', 'o.max_participants as option_max',
                'o.max_quantity', 'a.max_participants as activity_max']) as $r) {
            $key = "{$r->activity_booking_option_id}|{$r->schedule_period_id}|{$r->reservation_date}";
            $load[$key]['p'] = ($load[$key]['p'] ?? 0) + array_sum(json_decode($r->participants ?? '[]', true) ?: []);
            $load[$key]['q'] = ($load[$key]['q'] ?? 0) + (int) $r->quantity;
            $load[$key]['max_p'] = $r->option_max ?? $r->activity_max;
            $load[$key]['max_q'] = $r->max_quantity;
        }
        $this->assertNotEmpty($load);
        foreach ($load as $key => $departure) {
            $this->assertLessThanOrEqual($departure['max_p'] ?? PHP_INT_MAX, $departure['p'], "Too many people on departure {$key}");
            $this->assertLessThanOrEqual($departure['max_q'] ?? PHP_INT_MAX, $departure['q'], "Too many units on departure {$key}");
        }
    }

    public function test_places_already_taken_by_other_bookings_are_respected(): void
    {
        $id = $this->bookable()[0];
        DB::table('activities')->where('id', $id)->update(['max_participants' => 3]);

        $this->run1();

        $perDeparture = DB::table('reservations')->where('activity_id', $id)->whereIn('status', ['confirmed', 'completed'])->get(['schedule_period_id', 'reservation_date', 'participants', 'quantity'])
            ->groupBy(fn ($r) => "{$r->schedule_period_id}|{$r->reservation_date}")->map(fn ($rows) => $rows->sum(fn ($r) => array_sum(json_decode($r->participants ?? '[]', true) ?: [])));
        $this->assertTrue($perDeparture->isNotEmpty());
        $this->assertLessThanOrEqual(3, $perDeparture->max(), 'A small activity is never over its 3 places on a departure');
    }

    public function test_conversion_is_a_few_in_a_hundred_views_and_differs_between_activities(): void
    {
        $this->run1();
        $rates = [];

        foreach ($this->bookable() as $id) {
            $views = DB::table('activity_views')->where('activity_id', $id)->count();
            $kept = DB::table('reservations')->where('activity_id', $id)->whereIn('status', ['confirmed', 'completed'])->count();
            $rates[] = $kept / max(1, $views) * 100;
        }

        $this->assertGreaterThan(0.5, min($rates), 'Even the smallest activity has bookings');
        $this->assertLessThan(10, max($rates));
        $this->assertGreaterThan(2, max($rates) / min($rates));
    }

    // --- Nothing before the activity existed, nothing in the future ----------------------------------------------------------------

    public function test_nothing_happens_to_an_activity_before_it_existed_and_nothing_is_dated_after_now(): void
    {
        $this->run1();

        $this->assertSame(0, DB::table('activity_views as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->whereRaw('v.viewed_on < substr(a.created_at, 1, 10)')->count());
        $this->assertSame(0, $this->own()->join('activities as a', 'a.id', '=', 'reservations.activity_id')->whereColumn('reservations.created_at', '<', 'a.created_at')->count());
        $this->assertSame(0, DB::table('activities')->whereIn('id', $this->eligible())->whereNotNull('published_at')->where(fn ($q) => $q->whereColumn('published_at', '<', 'created_at')->orWhere('published_at', '>', '2026-09-17 00:00:00'))->count(), 'Published once created, and before the first of the data');
        foreach (['reservations' => ['created_at', 'confirmed_at', 'cancelled_at'], 'reviews' => ['created_at'], 'review_replies' => ['created_at'], 'payments' => ['created_at', 'paid_at']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame(0, DB::table($table)->where($column, '>', self::NOW)->count(), "{$table}.{$column} is in the future");
            }
        }
    }

    public function test_an_activity_is_only_made_older_never_newer_and_nothing_else_about_it_changes(): void
    {
        $before = DB::table('activities')->orderBy('id')->get()->keyBy('id');

        $this->run1();

        foreach (DB::table('activities')->orderBy('id')->get() as $after) {
            $was = $before[$after->id];
            $this->assertLessThanOrEqual($was->created_at, $after->created_at);
            foreach (['title', 'slug', 'status', 'description', 'provider_id', 'category_id', 'city_id'] as $column) {
                $this->assertEquals($was->$column, $after->$column, "{$column} of activity {$after->id}");
            }
        }
    }

    // --- Reviews and replies ---------------------------------------------------------------------------------------------------

    public function test_reviews_are_on_completed_trips_one_a_customer_mostly_good_and_some_are_answered(): void
    {
        $this->run1();

        $own = DB::table('reviews')->where('uuid', '!=', '')->whereIn('reservation_id', $this->own()->pluck('id'));
        $this->assertGreaterThan(20, (clone $own)->count());
        $this->assertSame(0, DB::table('reviews as v')->join('reservations as r', 'r.id', '=', 'v.reservation_id')->where('r.status', '!=', 'completed')->count());
        $this->assertSame(0, DB::table('reviews as v')->join('reservations as r', 'r.id', '=', 'v.reservation_id')->where(fn ($q) => $q->whereColumn('r.activity_id', '!=', 'v.activity_id')->orWhereColumn('r.customer_id', '!=', 'v.customer_id'))->count());
        $this->assertGreaterThan(3.5, (clone $own)->avg('rating'));
        $this->assertSame([1, 5], [(int) (clone $own)->min('rating'), (int) (clone $own)->max('rating')], 'Not all five stars');

        $answered = DB::table('review_replies')->where('is_active', 1)->count();
        $this->assertGreaterThan(0, $answered);
        $this->assertLessThan(DB::table('reviews')->count(), $answered, 'Not every review is answered');
    }

    public function test_a_reply_is_by_the_activitys_own_provider_after_the_review(): void
    {
        $this->run1();

        $this->assertSame(0, DB::table('review_replies as p')->join('reviews as v', 'v.id', '=', 'p.review_id')->join('activities as a', 'a.id', '=', 'v.activity_id')
            ->where(fn ($q) => $q->whereColumn('p.provider_id', '!=', 'a.provider_id')->orWhereColumn('p.created_at', '<', 'v.created_at'))->count());
    }

    public function test_the_average_and_count_on_each_activity_are_those_of_its_visible_reviews(): void
    {
        $this->run1();

        $this->assertGreaterThan(0, DB::table('activities')->where('reviews_count', '>', 0)->count());
        foreach (DB::table('activities')->where('reviews_count', '>', 0)->get(['id', 'average_rating', 'reviews_count']) as $a) {
            $stats = DB::table('reviews')->where('activity_id', $a->id)->where('is_hidden', 0)->selectRaw('AVG(rating) AS average, COUNT(*) AS total')->first();
            $this->assertEquals($stats->total, $a->reviews_count);
            $this->assertEqualsWithDelta((float) $stats->average, (float) $a->average_rating, 0.01);
        }
    }

    // --- Running it again ---------------------------------------------------------------------------------------------------------

    public function test_running_it_again_adds_nothing(): void
    {
        $this->run1();
        $first = $this->counts();

        $this->run1();
        $this->run1();

        $this->assertSame($first, $this->counts());
    }

    public function test_it_makes_the_same_data_every_time(): void
    {
        $this->run1();
        $first = $this->fingerprint();

        // Take away everything the seeder made, and make it again.
        DB::table('review_replies')->delete();
        DB::table('reviews')->whereIn('reservation_id', $this->own()->pluck('id'))->delete();
        DB::table('payments')->whereIn('reservation_id', $this->own()->pluck('id'))->delete();
        $this->own()->delete();
        DB::table('activity_views')->delete();
        $this->run1();

        $this->assertSame($first, $this->fingerprint());
    }

    public function test_on_a_later_day_it_adds_only_the_new_day_and_leaves_the_earlier_days_alone(): void
    {
        $this->run1();
        $before = DB::table('activity_views')->selectRaw('viewed_on, COUNT(*) AS n')->groupBy('viewed_on')->pluck('n', 'viewed_on')->all();
        $written = $this->own()->orderBy('uuid')->pluck('total', 'uuid')->all();
        $todayBefore = DB::table('activity_views')->where('viewed_on', '2026-11-15')->count();

        Carbon::setTestNow('2026-11-17 12:00:00');
        $this->run1();
        $after = DB::table('activity_views')->selectRaw('viewed_on, COUNT(*) AS n')->groupBy('viewed_on')->pluck('n', 'viewed_on')->all();

        foreach ($before as $day => $n) {
            if ($day !== '2026-11-15') {
                $this->assertSame($n, $after[$day], "{$day} did not change");
            }
        }
        $this->assertGreaterThan($todayBefore, $after['2026-11-15'], 'Yesterday of the new run was only half a day in the old one: now it is whole');
        $this->assertArrayHasKey('2026-11-17', $after);
        $this->assertArrayHasKey('2026-11-16', $after);
        foreach ($this->own()->whereIn('uuid', array_keys($written))->pluck('total', 'uuid') as $uuid => $total) {
            $this->assertSame((string) $written[$uuid], (string) $total, "Booking {$uuid} was changed");
        }
        // A booking from before today that is new now can only be one that was cancelled since: it is written once the cancellation has happened.
        $late = $this->own()->whereNotIn('uuid', array_keys($written))->where('created_at', '<', '2026-11-15')->get(['status', 'cancelled_at']);
        foreach ($late as $booking) {
            $this->assertSame(['cancelled', true], [$booking->status, $booking->cancelled_at > '2026-11-15 12:00:00']);
        }
        $this->assertNoDepartureOverCapacity();
    }

    public function test_building_it_up_day_by_day_gives_exactly_what_one_run_at_the_end_gives(): void
    {
        // In six-hour steps, so that reviews and replies become due part-way through a day, and then a day at a time.
        foreach (['2026-11-15 12:00:00', '2026-11-15 18:00:00', '2026-11-16 00:00:00', '2026-11-16 06:00:00', '2026-11-16 12:00:00', '2026-11-16 18:00:00', '2026-11-17 12:00:00', '2026-11-20 12:00:00'] as $now) {
            Carbon::setTestNow($now);
            $this->run1();
        }
        $stepwise = $this->fingerprint();
        $this->assertNoDepartureOverCapacity();

        // Everything the seeder made is taken away, and it is run once, on the last day.
        $ids = $this->own()->pluck('id');
        DB::table('review_replies')->delete();
        DB::table('reviews')->whereIn('reservation_id', $ids)->delete();
        DB::table('payments')->whereIn('reservation_id', $ids)->delete();
        $this->own()->delete();
        DB::table('activity_views')->delete();
        $this->run1();

        $this->assertSame($stepwise, $this->fingerprint(), 'Built day by day, it is exactly what one run at the end makes');
    }

    /**
     * A customer reviews an activity once. When they have two trips that qualify, WHICH one is reviewed must not depend on when the seeder runs. Here the later-numbered
     * trip becomes reviewable first (its review time is earlier), the very case that used to make a step-by-step build review a different trip from one run at the end.
     */
    public function test_a_customer_with_two_trips_is_reviewed_on_the_same_one_whenever_it_is_run(): void
    {
        $this->run1();
        $seeder = app(ProviderAnalyticsDemoSeeder::class);
        $reflect = fn (string $name) => (function () use ($name) {
            $r = new \ReflectionProperty(ProviderAnalyticsDemoSeeder::class, $name);
            $r->setAccessible(true);

            return $r;
        })();
        $reviewable = fn (string $uuid) => abs(crc32("analytics-review|{$uuid}|".config('seeding.seed'))) % 100000 < 30000;
        $offset = fn (string $uuid) => crc32($uuid) % 600;
        // Eligible for a review, as the seeder decides it, with a review time late enough in its day to leave room for one that is earlier.
        $first = $this->own()->where('status', 'completed')->orderBy('id')->get()->first(fn ($r) => $reviewable($r->uuid) && $offset($r->uuid) > 120);
        $this->assertNotNull($first, 'The demo data has a completed trip to build the case on');

        // Its customer's other reviews of this activity go, and a second trip is added: same customer, activity and day, a later id, a review time that is EARLIER.
        DB::table('review_replies')->whereIn('review_id', DB::table('reviews')->where('customer_id', $first->customer_id)->where('activity_id', $first->activity_id)->pluck('id'))->delete();
        DB::table('reviews')->where('customer_id', $first->customer_id)->where('activity_id', $first->activity_id)->delete();
        do {
            $uuid = (string) Str::uuid();
        } while (! $reviewable($uuid) || $offset($uuid) > $offset($first->uuid) - 60);
        $second = (array) DB::table('reservations')->where('id', $first->id)->first();
        unset($second['id']);
        DB::table('reservations')->insert(array_merge($second, ['uuid' => $uuid, 'idempotency_key' => 'analytics-demo-'.$uuid]));

        $activity = (object) ['id' => $first->activity_id, 'provider_id' => DB::table('activities')->where('id', $first->activity_id)->value('provider_id')];
        $dayAfter = Carbon::parse($first->reservation_date)->addDay()->setTime(10, 0);
        $review = fn () => DB::table('reviews')->where('customer_id', $first->customer_id)->where('activity_id', $first->activity_id)->pluck('reservation_id')->all();
        $make = function (Carbon $now) use ($seeder, $reflect, $activity, $first, $uuid) {
            $reflect('now')->setValue($seeder, $now);
            $method = new \ReflectionMethod($seeder, 'reviewCompleted');
            $method->setAccessible(true);
            $method->invoke($seeder, $activity, [$first->uuid, $uuid], ['quality' => 2, 'answers' => 0.5]);
        };
        $secondId = (int) DB::table('reservations')->where('uuid', $uuid)->value('id');

        // Part-way through the day after the trips: the second one's review is due, the first one's is not.
        $make($dayAfter->copy()->addMinutes(($offset($uuid) + $offset($first->uuid)) / 2));
        $this->assertSame([], $review(), 'Neither is reviewed yet: the customer\'s review is the first trip\'s, and its time has not come');

        // Later, both are due: the review is on the FIRST trip, as it would have been had the seeder run once at the end.
        $make($dayAfter->copy()->addDay());
        $this->assertSame([$first->id], $review());
        $this->assertNotSame($secondId, $first->id);
    }

    public function test_later_the_same_day_it_adds_only_the_hours_of_today_that_have_passed_and_the_same_as_one_run_then(): void
    {
        $this->run1();
        $before = DB::table('activity_views')->where('viewed_on', '<', '2026-11-15')->count();
        $today = DB::table('activity_views')->where('viewed_on', '2026-11-15')->count();

        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->run1();

        $this->assertSame($before, DB::table('activity_views')->where('viewed_on', '<', '2026-11-15')->count());
        $this->assertGreaterThan($today, DB::table('activity_views')->where('viewed_on', '2026-11-15')->count());
    }

    public function test_right_at_the_start_of_the_day_today_has_no_views_yet_and_nothing_breaks(): void
    {
        Carbon::setTestNow('2026-11-15 00:00:00');

        $this->run1();

        $this->assertSame(0, DB::table('activity_views')->where('viewed_on', '2026-11-15')->count());
        $this->assertGreaterThan(0, DB::table('activity_views')->where('viewed_on', '2026-11-14')->count());
        $this->assertSame(0, $this->own()->where('created_at', '>=', '2026-11-15')->count());
    }

    public function test_on_the_first_of_the_month_the_period_before_it_is_still_there(): void
    {
        Carbon::setTestNow('2026-12-01 12:00:00');

        $this->run1();

        $this->assertSame('2026-09-30', DB::table('activity_views')->min('viewed_on'), '62 days before the 1st of December');
        $this->assertGreaterThan(0, DB::table('activity_views')->where('viewed_on', '2026-11-30')->count(), 'The period before a one-day month is the day before it');
        $this->assertGreaterThan(0, DB::table('activity_views')->where('viewed_on', '2026-12-01')->count());
    }

    // --- What it leaves alone ---------------------------------------------------------------------------------------------------

    public function test_it_adds_no_users_providers_activities_or_catalogue(): void
    {
        $tables = ['users', 'providers', 'customers', 'activities', 'categories', 'activity_booking_options', 'activity_pricing_rules', 'activity_schedule_periods', 'cities', 'coupons', 'wallet_entries'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);

        $this->run1();

        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables));
    }

    public function test_it_stops_with_a_clear_message_when_there_is_nothing_to_build_on(): void
    {
        DB::table('activity_booking_options')->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs rows in "activity_booking_options"');

        $this->run1();
    }

    // --- What the analytics endpoint then says --------------------------------------------------------------------------------

    private function busiestProvider(): array
    {
        $row = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->whereIn('r.status', ['completed', 'confirmed'])->selectRaw('a.provider_id, COUNT(*) AS n')
            ->groupBy('a.provider_id')->orderByDesc('n')->first();
        $provider = Provider::find($row->provider_id);

        return [$provider, $this->tokenFor($provider->user, 'provider')];
    }

    private function analytics(string $token, string $query = '')
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->getJson('/api/v1/provider/analytics'.$query)->assertOk()->json('data');
    }

    public function test_the_default_period_and_this_month_are_full_of_data_with_every_change_figure(): void
    {
        $this->run1();
        [, $token] = $this->busiestProvider();

        foreach (['', '?from=2026-11-01&to=2026-11-15'] as $query) {
            $d = $this->analytics($token, $query);

            $this->assertGreaterThan(100, $d['page_views'], $query);
            $this->assertNotNull($d['page_views_change'], $query);
            $this->assertGreaterThan(0, $d['conversion_rate'], $query);
            $this->assertNotNull($d['conversion_change'], $query);
            $this->assertNotNull($d['avg_rating'], $query);
            $this->assertNotNull($d['response_rate'], $query);
            $this->assertNotEmpty($d['top_activities'], $query);
            $this->assertSame(100, array_sum(array_column($d['booking_sources'], 'percent')), $query);
            $this->assertGreaterThan(1, count(array_filter(array_column($d['booking_sources'], 'percent'))), 'More than one source brings bookings');
        }
    }

    public function test_top_activities_are_ranked_by_revenue_and_the_bookings_add_up(): void
    {
        $this->run1();
        [, $token] = $this->busiestProvider();

        $top = $this->analytics($token, '?from=2026-10-16&to=2026-11-15')['top_activities'];

        $this->assertGreaterThan(0, count($top));
        $gross = array_column($top, 'gross_revenue');
        $sorted = $gross;
        rsort($sorted);
        $this->assertSame($sorted, $gross, 'Best first');
        foreach ($top as $row) {
            $this->assertGreaterThan(0, $row['bookings']);
            $this->assertGreaterThan(0, $row['net_revenue']);
            $this->assertLessThanOrEqual($row['gross_revenue'], $row['net_revenue'], 'Net is after the provider\'s commission, so never more than gross');
            $this->assertGreaterThan($row['gross_revenue'] * 0.5, $row['net_revenue']);
        }
    }

    public function test_one_activity_has_its_own_numbers(): void
    {
        $this->run1();
        [$provider, $token] = $this->busiestProvider();
        $activities = DB::table('activities')->where('provider_id', $provider->id)->whereIn('id', $this->bookable())->orderBy('id')->pluck('id');
        $this->assertGreaterThan(0, $activities->count());

        foreach ($activities as $id) {
            $d = $this->analytics($token, "?activity_id={$id}&from=2026-11-01&to=2026-11-15");

            $this->assertSame(DB::table('activity_views')->where('activity_id', $id)->whereBetween('viewed_on', ['2026-11-01', '2026-11-15'])->count(), $d['page_views'], "Views of {$id}");
            $this->assertLessThanOrEqual(1, count($d['top_activities']));
            foreach ($d['top_activities'] as $row) {
                $this->assertSame($id, $row['id']);
            }
        }
    }

    public function test_a_published_activity_that_cannot_be_booked_still_shows_its_views_and_no_bookings(): void
    {
        $this->run1();
        $notBookable = [$this->unbookable];
        $provider = Provider::find(DB::table('activities')->where('id', $notBookable[0])->value('provider_id'));

        $d = $this->analytics($this->tokenFor($provider->user, 'provider'), "?activity_id={$notBookable[0]}&from=2026-11-01&to=2026-11-15");

        $this->assertGreaterThan(0, $d['page_views']);
        $this->assertSame([0.0, []], [(float) $d['conversion_rate'], $d['top_activities']]);
        $this->assertSame(0, array_sum(array_column($d['booking_sources'], 'percent')));
    }
}
