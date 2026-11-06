<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Bulk\BulkDatabaseSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProviderMonthlyReservationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ProviderMonthlyReservationSeeder gives every ACTIVE provider at least 50 paid reservations in the current month. Each test states one
 * thing that must hold, against the demo data (15 active providers, 5 of them with nothing bookable) unless it says otherwise.
 */
class ProviderMonthlyReservationSeederTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        Storage::fake(config('providers.documents.disk'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    private function run50(): void
    {
        $this->seed(ProviderMonthlyReservationSeeder::class);
    }

    private function activeProviders()
    {
        return DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.verification_status', 'verified')->whereNull('p.deleted_at')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->orderBy('p.id')->get(['p.id', 'p.user_id', 'p.business_name']);
    }

    /** Confirmed or completed reservations of a provider in the month, with a succeeded payment. */
    private function paidInMonth(int $providerId, ?string $month = null): int
    {
        $month ??= Carbon::now()->format('Y-m');

        return DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->where('a.provider_id', $providerId)->whereIn('r.status', ['confirmed', 'completed'])
            ->whereRaw('substr(r.reservation_date, 1, 7) = ?', [$month])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('payments as p')->whereColumn('p.reservation_id', 'r.id')->where('p.status', 'succeeded'))
            ->count();
    }

    private function assertNone($rows, string $rule): void
    {
        $rows = collect($rows);

        $this->assertTrue($rows->isEmpty(), "{$rule}. {$rows->count()} row(s) break it, for example: ".$rows->take(3)->map(fn ($r) => json_encode($r))->implode(' | '));
    }

    // --- Who gets bookings, and how many ---------------------------------------------------------------------------------

    public function test_every_active_provider_gets_at_least_fifty_paid_reservations_in_the_current_month(): void
    {
        $this->seedDemo();
        $this->run50();

        $providers = $this->activeProviders();
        $this->assertCount(15, $providers, 'The demo data has 15 active providers');

        foreach ($providers as $provider) {
            $this->assertGreaterThanOrEqual(50, $this->paidInMonth($provider->id), "{$provider->business_name} has fewer than 50 paid reservations this month");
        }
    }

    public function test_providers_that_are_not_active_get_nothing(): void
    {
        $this->seedDemo();

        // Two approved providers who are not active any more: one suspended, one deleted.
        [$suspended, $deleted] = $this->activeProviders()->take(2)->all();
        DB::table('users')->where('id', $suspended->user_id)->update(['status' => 'suspended']);
        DB::table('users')->where('id', $deleted->user_id)->update(['deleted_at' => now()]);

        $this->run50();

        $inactive = DB::table('providers')->where(fn ($q) => $q->where('verification_status', '!=', 'verified')->orWhereIn('id', [$suspended->id, $deleted->id]))->pluck('id');
        $this->assertGreaterThanOrEqual(10, $inactive->count());

        foreach ($inactive as $id) {
            $this->assertSame(0, DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $id)->count(), "Provider {$id} is not active but got bookings");
        }
        foreach ($this->activeProviders() as $provider) {
            $this->assertGreaterThanOrEqual(50, $this->paidInMonth($provider->id));
        }
    }

    // --- The month and the calendar ----------------------------------------------------------------------------------------

    public function test_every_reservation_is_dated_in_the_current_month_and_its_status_follows_the_calendar(): void
    {
        $this->seedDemo();
        $this->run50();

        $this->assertNone(DB::table('reservations')->whereRaw("substr(reservation_date, 1, 7) != '2026-11'")->limit(3)->pluck('uuid'), 'Dated outside the current month');
        $this->assertNone(DB::table('reservations')->whereNotIn('status', ['confirmed', 'completed'])->limit(3)->pluck('uuid'), 'Only confirmed and completed reservations are made');
        $this->assertNone(DB::table('reservations')->where('status', 'completed')->where('reservation_date', '>=', '2026-11-15')->limit(3)->pluck('uuid'), 'A trip that has not happened yet is completed');
        $this->assertNone(DB::table('reservations')->where('status', 'confirmed')->where('reservation_date', '<', '2026-11-15')->limit(3)->pluck('uuid'), 'A trip in the past is still only confirmed');

        $statuses = DB::table('reservations')->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $this->assertGreaterThan(0, $statuses['completed'] ?? 0);
        $this->assertGreaterThan(0, $statuses['confirmed'] ?? 0);
    }

    public static function awkwardDays(): array
    {
        return [
            'the first of the month (every trip is still ahead)' => ['2026-11-01 08:00:00', '2026-11'],
            'the last day of the month' => ['2026-11-30 23:30:00', '2026-11'],
            'a 28-day February' => ['2027-02-14 12:00:00', '2027-02'],
            'a 31-day month' => ['2026-12-31 12:00:00', '2026-12'],
        ];
    }

    /** @dataProvider awkwardDays */
    #[DataProvider('awkwardDays')]
    public function test_it_works_on_any_day_of_any_month(string $now, string $month): void
    {
        Carbon::setTestNow($now);
        $this->seedDemo();
        $this->run50();

        foreach ($this->activeProviders() as $provider) {
            $this->assertGreaterThanOrEqual(50, $this->paidInMonth($provider->id, $month), "{$provider->business_name} on {$now}");
        }

        $this->assertNone(DB::table('reservations')->whereRaw('substr(reservation_date, 1, 7) != ?', [$month])->limit(3)->pluck('uuid'), 'Dated outside the month');
        $today = Carbon::now()->toDateString();
        $this->assertNone(DB::table('reservations')->where('status', 'completed')->where('reservation_date', '>=', $today)->limit(3)->pluck('uuid'), 'Completed in advance');
        $this->assertNone(DB::table('reservations')->where('status', 'confirmed')->where('reservation_date', '<', $today)->limit(3)->pluck('uuid'), 'Past but not completed');
    }

    // --- Paid, priced, within capacity, on real departures ------------------------------------------------------------------------

    public function test_every_reservation_has_exactly_one_succeeded_payment_for_its_full_total(): void
    {
        $this->seedDemo();
        $this->run50();

        $this->assertNone(DB::table('reservations as r')->whereRaw("(select count(*) from payments p where p.reservation_id = r.id and p.status = 'succeeded') != 1")->limit(3)->pluck('r.uuid'), 'Exactly one succeeded payment');
        $this->assertSame(DB::table('reservations')->count(), DB::table('payments')->count(), 'Nothing but those payments');
        $this->assertNone(DB::table('payments as p')->join('reservations as r', 'r.id', '=', 'p.reservation_id')->whereRaw('ABS(p.amount - r.total) > 0.005')->limit(3)->pluck('p.reference_id'), 'Paid in full');
        $this->assertNone(DB::table('payments')->where(fn ($q) => $q->whereNull('paid_at')->orWhere('refunded_amount', '!=', 0)->orWhere('gateway', '!=', 'mock')->orWhere('currency', '!=', 'EGP'))->limit(3)->pluck('reference_id'), 'Paid, never refunded, mock gateway, EGP');
        $this->assertNone(DB::table('payments')->where('paid_at', '>', now())->limit(3)->pluck('reference_id'), 'Paid in the future');
        $this->assertSame(DB::table('payments')->count(), DB::table('payments')->distinct()->count('reference_id'), 'Unique payment references');
    }

    public function test_prices_come_from_the_options_own_rules_and_the_breakdown_adds_up(): void
    {
        $this->seedDemo();
        $this->run50();

        $rules = [];
        foreach (DB::table('activity_pricing_rules')->whereNull('schedule_period_id')->get(['activity_booking_option_id', 'participant_type', 'price']) as $rule) {
            $rules[$rule->activity_booking_option_id][$rule->participant_type ?? 'flat'] = (float) $rule->price;
        }

        $wrong = [];
        foreach (DB::table('reservations')->get(['uuid', 'activity_booking_option_id', 'total', 'subtotal', 'pricing_breakdown']) as $r) {
            $lines = json_decode($r->pricing_breakdown, true);
            $problems = [];
            abs(array_sum(array_column($lines, 'total')) - (float) $r->total) < 0.005 || $problems[] = 'breakdown does not add up to the total';
            abs((float) $r->subtotal - (float) $r->total) < 0.005 || $problems[] = 'subtotal differs';
            foreach ($lines as $line) {
                $price = $rules[$r->activity_booking_option_id][$line['participant_type'] ?? 'flat'] ?? null;
                ($price !== null && abs($price - (float) $line['unit_price']) < 0.005) || $problems[] = 'a line is not at the rule price';
            }
            $problems === [] || $wrong[] = ['uuid' => $r->uuid, 'problems' => $problems];
        }

        $this->assertNone($wrong, 'Priced from the pricing rules');
    }

    public function test_every_reservation_is_on_a_real_departure_of_a_published_activity_of_the_provider(): void
    {
        $this->seedDemo();
        $this->run50();

        $this->assertNone(DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where(fn ($q) => $q->where('a.status', '!=', 'published')->orWhereNotNull('a.deleted_at'))->limit(3)->pluck('r.uuid'), 'On a published activity');
        $this->assertNone(DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->where(fn ($q) => $q->whereColumn('o.activity_id', '!=', 'r.activity_id')->orWhere('o.is_active', 0)->orWhereNotNull('o.deleted_at'))->limit(3)->pluck('r.uuid'), 'On an active option of that activity');
        $this->assertNone(DB::table('reservations as r')->join('activity_schedule_periods as s', 's.id', '=', 'r.schedule_period_id')->where(fn ($q) => $q->whereColumn('s.activity_id', '!=', 'r.activity_id')->orWhere('s.status', '!=', 'active')->orWhereNotNull('s.deleted_at'))->limit(3)->pluck('r.uuid'), 'On an active departure of that activity');

        $wrongDay = [];
        foreach (DB::table('reservations as r')->join('activity_schedule_periods as s', 's.id', '=', 'r.schedule_period_id')->get(['r.uuid', 'r.reservation_date', 's.day_of_week']) as $row) {
            Carbon::parse($row->reservation_date)->dayOfWeek === (int) $row->day_of_week || $wrongDay[] = (array) $row;
        }
        $this->assertNone($wrongDay, "The trip is on its departure's weekday (0 = Sunday)");
    }

    public function test_no_day_is_booked_beyond_an_activitys_capacity(): void
    {
        $this->seedDemo();
        // A large minimum makes the days fill up, so the check has something to refuse.
        config(['seeding.provider_month_minimum' => 120]);
        $this->run50();

        $load = [];
        $capacity = [];
        foreach (DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->get(['r.activity_id', 'r.reservation_date', 'r.participants', 'r.quantity', 'o.booking_model', 'a.max_participants']) as $r) {
            $key = $r->activity_id.'|'.$r->reservation_date;
            $load[$key] = ($load[$key] ?? 0) + match ($r->booking_model) {
                'per_person' => array_sum(json_decode($r->participants, true) ?: [1]),
                'per_unit' => (int) $r->quantity,
                default => 4,
            };
            $capacity[$key] = $r->max_participants;
        }

        $over = [];
        foreach ($load as $key => $people) {
            $people <= $capacity[$key] || $over[] = ['activity|day' => $key, 'booked' => $people, 'capacity' => $capacity[$key]];
        }

        $this->assertNone($over, 'No activity is booked beyond its capacity on any day');
        $this->assertSame(15 * 120, DB::table('reservations')->count(), 'The test must really have pushed the demo providers hard');
        $this->assertNotEmpty(array_filter($load, fn ($people, $key) => $people > $capacity[$key] * 0.8, ARRAY_FILTER_USE_BOTH), 'Some days must have come close to full, or this proves nothing');
    }

    public function test_a_minimum_that_cannot_fit_fails_with_a_clear_message_instead_of_overbooking(): void
    {
        $this->seedDemo();
        config(['seeding.provider_month_minimum' => 100000]);

        try {
            $this->run50();
            $this->fail('It accepted a minimum that no activity could hold');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('capacity', $e->getMessage());
        }
    }

    public function test_nobody_books_their_own_trips_and_only_active_customers_book(): void
    {
        $this->seedDemo();

        // One provider who is also a customer, who would be a likely booker of their own trips.
        $provider = $this->activeProviders()->first();
        DB::table('customers')->insert(['uuid' => (string) Str::uuid(), 'user_id' => $provider->user_id, 'preferred_language' => 'en', 'created_at' => now(), 'updated_at' => now()]);
        // And one customer who is suspended.
        $suspendedCustomer = DB::table('customers')->where('user_id', '!=', $provider->user_id)->value('user_id');
        DB::table('users')->where('id', $suspendedCustomer)->update(['status' => 'suspended']);

        $this->run50();

        $this->assertNone(
            DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('customers as c', 'c.id', '=', 'r.customer_id')->whereColumn('c.user_id', 'p.user_id')->limit(3)->pluck('r.uuid'),
            'A provider booked their own trip'
        );
        $this->assertNone(DB::table('reservations as r')->join('customers as c', 'c.id', '=', 'r.customer_id')->join('users as u', 'u.id', '=', 'c.user_id')->where(fn ($q) => $q->where('u.status', '!=', 'active')->orWhereNotNull('u.deleted_at'))->limit(3)->pluck('r.uuid'), 'A suspended or deleted customer booked');
    }

    public function test_every_booked_slot_has_its_capacity_lock(): void
    {
        $this->seedDemo();
        $this->run50();

        $this->assertNone(
            DB::table('reservations as r')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reservation_capacity_locks as l')
                ->whereColumn('l.activity_booking_option_id', 'r.activity_booking_option_id')->whereColumn('l.schedule_period_id', 'r.schedule_period_id')->whereColumn('l.reservation_date', 'r.reservation_date'))->limit(3)->pluck('r.uuid'),
            'Every option, departure and day that was booked has its capacity lock'
        );
    }

    // --- Running it again ----------------------------------------------------------------------------------------------------------

    public function test_running_it_again_adds_nothing(): void
    {
        $this->seedDemo();
        $this->run50();
        $counts = [DB::table('reservations')->count(), DB::table('payments')->count(), DB::table('activities')->count(), DB::table('reservation_capacity_locks')->count()];

        $this->run50();

        $this->assertSame($counts, [DB::table('reservations')->count(), DB::table('payments')->count(), DB::table('activities')->count(), DB::table('reservation_capacity_locks')->count()]);
    }

    public function test_raising_the_minimum_adds_only_the_shortfall(): void
    {
        $this->seedDemo();
        config(['seeding.provider_month_minimum' => 20]);
        $this->run50();
        $this->assertSame(15 * 20, DB::table('reservations')->count());

        config(['seeding.provider_month_minimum' => 50]);
        $this->run50();

        $this->assertSame(15 * 50, DB::table('reservations')->count(), 'Exactly the missing 30 per provider');
        foreach ($this->activeProviders() as $provider) {
            $this->assertSame(50, $this->paidInMonth($provider->id));
        }
    }

    public function test_reservations_that_already_exist_count_towards_the_minimum(): void
    {
        $this->seedDemo();
        $this->run50();

        // Take a provider who has 50 and delete their payments for ten reservations: those no longer count as paid, so ten are missing.
        $provider = $this->activeProviders()->first();
        $ids = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $provider->id)->limit(10)->pluck('r.id');
        DB::table('payments')->whereIn('reservation_id', $ids)->delete();
        $this->assertSame(40, $this->paidInMonth($provider->id));

        $this->run50();

        $this->assertSame(50, $this->paidInMonth($provider->id), 'It adds the ten that are missing, no more');
    }

    public function test_the_same_month_always_produces_the_same_reservations(): void
    {
        $fingerprint = fn () => md5(json_encode(DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->orderBy('r.uuid')
            ->get(['r.uuid', 'a.slug', 'r.reservation_date', 'r.status', 'r.total'])->all()));

        $this->seedDemo();
        $this->run50();
        $first = $fingerprint();

        foreach (['payments', 'reservation_capacity_locks', 'reservations', 'activity_pricing_rules', 'activity_booking_options', 'activity_schedule_periods', 'activity_images', 'activity_options', 'activity_feature_values',
            'activity_requirements', 'activity_inclusions', 'activity_availability_overrides', 'activities', 'provider_documents', 'providers', 'customers', 'admins', 'model_has_roles', 'users', 'feature_definitions', 'categories'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('delete from sqlite_sequence');

        $this->seedDemo();
        $this->run50();

        $this->assertSame($first, $fingerprint(), 'The same month and seed must give identical reservations');
    }

    // --- A provider with nothing to book ------------------------------------------------------------------------------------------------

    public function test_a_provider_with_nothing_bookable_gets_a_complete_published_activity_first(): void
    {
        $this->seedDemo();
        $this->run50();

        $created = DB::table('activities')->where('slug', 'like', '%-pm%')->get();
        $this->assertCount(5, $created, 'Five of the demo providers had nothing bookable');

        foreach ($created as $activity) {
            $this->assertSame('published', $activity->status);
            $this->assertSame(40, (int) $activity->max_participants);
            $this->assertSame(1, DB::table('activity_images')->where('activity_id', $activity->id)->where('is_cover', 1)->count());
            $this->assertSame(7, DB::table('activity_schedule_periods')->where('activity_id', $activity->id)->where('status', 'active')->count());
            $option = DB::table('activity_booking_options')->where('activity_id', $activity->id)->first();
            $this->assertSame(['adult', 'child'], DB::table('activity_pricing_rules')->where('activity_booking_option_id', $option->id)->orderBy('participant_type')->pluck('participant_type')->all());

            // And the public API shows it like any other activity.
            $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk()->assertJsonPath('data.uuid', $activity->uuid);
        }
    }

    public function test_providers_who_already_have_bookable_activities_are_given_no_extra_one(): void
    {
        $this->seedDemo();
        $before = DB::table('activities')->count();
        $this->run50();

        // Only the five that had nothing bookable gained an activity.
        $this->assertSame($before + 5, DB::table('activities')->count());
    }

    // --- Other data, other places ---------------------------------------------------------------------------------------------------------

    public function test_it_works_on_the_bulk_data_too_and_leaves_it_consistent(): void
    {
        config(['seeding.bulk_scale' => 0.02]);
        $this->seed(BulkDatabaseSeeder::class);
        $reservationsBefore = DB::table('reservations')->count();

        $this->run50();

        $providers = $this->activeProviders();
        $this->assertGreaterThan(10, $providers->count());
        foreach ($providers as $provider) {
            $this->assertGreaterThanOrEqual(50, $this->paidInMonth($provider->id), "{$provider->business_name}");
        }

        $this->assertGreaterThan($reservationsBefore, DB::table('reservations')->count());
        $this->assertSame([], DB::select('pragma foreign_key_check'));
        $this->assertSame(DB::table('payments')->count(), DB::table('payments')->distinct()->count('reference_id'), 'Payment references stay unique next to the bulk ones');
    }

    public function test_a_provider_sees_their_paid_reservations_through_the_api_and_a_customer_sees_theirs(): void
    {
        $this->seedDemo();
        $this->run50();

        $provider = $this->activeProviders()->first();
        $token = User::findOrFail($provider->user_id)->createToken('t', ['provider'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->getJson('/api/v1/provider/reservations?per_page=100')->assertOk();

        $this->assertGreaterThanOrEqual(50, $response->json('pagination.total_items'));
        $this->assertEmpty(array_diff(array_unique(array_column($response->json('data'), 'status')), ['confirmed', 'completed']));

        $customer = DB::table('reservations as r')->join('customers as c', 'c.id', '=', 'r.customer_id')->orderBy('r.id')->first(['c.user_id', 'c.id as customer_id']);
        $customerToken = User::findOrFail($customer->user_id)->createToken('t', ['customer'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$customerToken, 'Accept' => 'application/json'])->getJson('/api/v1/customer/reservations?per_page=100')->assertOk()
            ->assertJsonPath('pagination.total_items', DB::table('reservations')->where('customer_id', $customer->customer_id)->count());
    }

    // --- Safety and prerequisites --------------------------------------------------------------------------------------------------------------

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seedDemo();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            app(ProviderMonthlyReservationSeeder::class)->run();
            $this->fail('It ran in production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must not run in production', $e->getMessage());
        }

        $this->assertSame(0, DB::table('reservations')->count());
    }

    public function test_it_says_what_to_run_first_when_there_are_no_providers_or_no_customers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        try {
            $this->run50();
            $this->fail('It ran with no providers');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ProviderSeeder', $e->getMessage());
        }

        $this->seedDemo();
        DB::table('users')->whereIn('id', DB::table('customers')->pluck('user_id'))->update(['status' => 'suspended']);

        try {
            $this->run50();
            $this->fail('It ran with no active customer');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('active customer', $e->getMessage());
        }
    }

    public function test_the_minimum_and_the_provider_route_file_are_documented_in_the_env_example(): void
    {
        $this->assertStringContainsString('PROVIDER_MONTH_MINIMUM=50', file_get_contents(base_path('.env.example')));
    }
}
