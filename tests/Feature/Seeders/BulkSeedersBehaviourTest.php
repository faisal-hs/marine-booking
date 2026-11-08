<?php

namespace Tests\Feature\Seeders;

use Carbon\Carbon;
use Database\Seeders\Bulk\BulkDatabaseSeeder;
use Database\Seeders\Bulk\BulkSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * How the Bulk seeders BEHAVE, as opposed to what they produce (that is BulkDataIntegrityTest): one per model, each
 * runnable alone and clear about what it needs, safe to run again, deterministic, and refused in production.
 */
class BulkSeedersBehaviourTest extends TestCase
{
    use RefreshDatabase;

    /** Every table the seeders fill. */
    private const TABLES = ['users', 'customers', 'providers', 'provider_requests', 'provider_profile_changes', 'wallet_entries', 'withdrawals', 'coupons', 'coupon_redemptions', 'review_replies', 'activity_views', 'verification_document_types', 'provider_document_events', 'provider_documents', 'admins', 'categories', 'feature_definitions', 'activities', 'activity_images',
        'activity_feature_values', 'activity_requirements', 'activity_inclusions', 'activity_options', 'activity_schedule_periods', 'activity_availability_overrides',
        'activity_booking_options', 'activity_pricing_rules', 'reservations', 'reservation_capacity_locks', 'payments', 'reviews', 'favorites', 'devices', 'otps',
        'notifications', 'broadcasts', 'model_has_roles'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('providers.documents.disk'));
        config(['seeding.bulk_scale' => 0.003]);
    }

    private function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function baseData(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\CategorySeeder::class);
        $this->seed(\Database\Seeders\FeatureDefinitionSeeder::class);
    }

    // --- One seeder per model ------------------------------------------------------------------------

    public function test_every_model_has_its_own_bulk_seeder_and_the_orchestrator_runs_it(): void
    {
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $model = basename($file, '.php');
            $seeder = "Database\\Seeders\\Bulk\\Bulk{$model}Seeder";

            if (! class_exists($seeder) || ! is_subclass_of($seeder, BulkSeeder::class) || ! in_array($seeder, BulkDatabaseSeeder::SEEDERS, true)) {
                $missing[] = $model;
            }
        }

        $this->assertSame([], $missing, 'These models have no Bulk seeder, or the orchestrator does not run it (add Bulk<Model>Seeder and list it in BulkDatabaseSeeder::SEEDERS): '.implode(', ', $missing));
        // Laravel's notifications table is not a model, but it is part of the app's data.
        $this->assertContains(\Database\Seeders\Bulk\BulkNotificationSeeder::class, BulkDatabaseSeeder::SEEDERS);
        $this->assertSame(count(BulkDatabaseSeeder::SEEDERS), count(array_unique(BulkDatabaseSeeder::SEEDERS)), 'A seeder is listed twice');
    }

    public function test_every_listed_seeder_documents_its_volume_and_prerequisites(): void
    {
        foreach (BulkDatabaseSeeder::SEEDERS as $seeder) {
            $doc = (new ReflectionClass($seeder))->getDocComment();

            $this->assertStringContainsString('Volume at scale 1', (string) $doc, class_basename($seeder).' does not say how much it creates at scale 1');
            $this->assertStringContainsString('Prerequisites', (string) $doc, class_basename($seeder).' does not say what it needs first');
        }
    }

    // --- Running them ------------------------------------------------------------------------------------

    public function test_the_orchestrator_fills_every_table(): void
    {
        $this->seed(BulkDatabaseSeeder::class);

        foreach ($this->counts() as $table => $rows) {
            $this->assertGreaterThan(0, $rows, "{$table} is empty after the orchestrator");
        }
    }

    public function test_each_seeder_runs_on_its_own_in_order(): void
    {
        $this->baseData();

        foreach (BulkDatabaseSeeder::SEEDERS as $seeder) {
            $before = $this->counts();
            $this->seed($seeder);

            $this->assertNotSame($before, $this->counts(), class_basename($seeder).' added nothing when run on its own');
        }
    }

    public function test_a_seeder_says_what_to_run_first_when_something_it_builds_on_is_missing(): void
    {
        $this->baseData();

        // Only the seeders with no prerequisite of their own can run on an otherwise empty database.
        $independent = [\Database\Seeders\Bulk\BulkCategorySeeder::class, \Database\Seeders\Bulk\BulkUserSeeder::class, \Database\Seeders\Bulk\BulkVerificationDocumentTypeSeeder::class];

        foreach (array_diff(BulkDatabaseSeeder::SEEDERS, $independent) as $seeder) {
            try {
                $this->seed($seeder);
                $this->fail(class_basename($seeder).' ran with nothing to build on');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(class_basename($seeder), $e->getMessage());
                $this->assertMatchesRegularExpression('/Run \w+/', $e->getMessage(), 'It should name the seeder to run first');
            }
        }

        foreach ($independent as $seeder) {
            $this->seed($seeder);
        }
        $this->assertGreaterThan(0, DB::table('users')->count());
    }

    public function test_the_staff_seeder_asks_for_the_roles_when_they_are_missing(): void
    {
        $this->seed(\Database\Seeders\Bulk\BulkUserSeeder::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RolesAndPermissionsSeeder');

        $this->seed(\Database\Seeders\Bulk\BulkAdminSeeder::class);
    }

    // --- Safe to repeat -------------------------------------------------------------------------------------

    public function test_running_everything_again_adds_nothing(): void
    {
        $this->seed(BulkDatabaseSeeder::class);
        $first = $this->counts();

        $this->seed(BulkDatabaseSeeder::class);

        $this->assertSame($first, $this->counts(), 'A second run must not add or duplicate anything');
    }

    public function test_raising_the_scale_adds_only_the_difference(): void
    {
        $this->seed(BulkDatabaseSeeder::class);
        $before = DB::table('activities')->count();
        $users = DB::table('users')->count();
        $firstIds = DB::table('activities')->orderBy('id')->limit(20)->pluck('uuid')->all();

        config(['seeding.bulk_scale' => 0.006]);
        $this->seed(BulkDatabaseSeeder::class);

        $this->assertSame($before * 2, DB::table('activities')->count(), 'Doubling the scale doubles the activities');
        $this->assertGreaterThan($users, DB::table('users')->count());
        $this->assertSame($firstIds, DB::table('activities')->orderBy('id')->limit(20)->pluck('uuid')->all(), 'What was there before is untouched');
        $this->assertSame([], DB::select('pragma foreign_key_check'));
    }

    public function test_a_crowd_on_few_activities_never_overbooks_a_day(): void
    {
        // A handful of activities, then thirty times the bookings: the days fill up, and the overflow must become
        // cancelled bookings rather than overbooked days. (At normal scales demand is too thin to ever fill a day.)
        $this->seed(BulkDatabaseSeeder::class);
        $activities = DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->count();

        config(['seeding.bulk_scale' => 0.2]);
        $this->seed(\Database\Seeders\Bulk\BulkReservationSeeder::class);

        $total = DB::table('reservations')->count();
        $this->assertSame(30000, $total);
        $this->assertLessThan(60, $activities, 'This test is about many bookings on few activities');

        $load = [];
        $capacity = [];
        foreach (DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->whereIn('r.status', ['confirmed', 'completed', 'pending'])->get(['r.activity_id', 'r.reservation_date', 'r.participants', 'r.quantity', 'o.booking_model', 'a.max_participants']) as $r) {
            $key = $r->activity_id.'|'.$r->reservation_date;
            $load[$key] = ($load[$key] ?? 0) + match ($r->booking_model) {
                'per_person' => array_sum(json_decode($r->participants, true) ?: [1]),
                'per_unit' => (int) $r->quantity,
                default => 4,
            };
            $capacity[$key] = $r->max_participants;
        }

        $over = array_filter($load, fn ($people, $key) => $people > $capacity[$key], ARRAY_FILTER_USE_BOTH);
        $this->assertSame([], array_slice($over, 0, 3, true), 'A day is booked beyond its capacity');

        $cancelled = DB::table('reservations')->where('status', 'cancelled')->count();
        $this->assertGreaterThan($total * 0.165, $cancelled, 'With this much demand the full days must have turned bookings away: the cancelled share is normally 15%');
        $this->assertGreaterThan(0, count($load));

        // And running it again adds nothing.
        $this->seed(\Database\Seeders\Bulk\BulkReservationSeeder::class);
        $this->assertSame($total, DB::table('reservations')->count());
    }

    // --- Deterministic -------------------------------------------------------------------------------------------

    public function test_the_same_scale_always_produces_the_same_data(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');
        // Compared by uuids, phones and slugs rather than row ids, so the claim is about the data itself.
        $fingerprint = function (): string {
            return md5(json_encode([
                DB::table('users')->orderBy('uuid')->get(['uuid', 'name', 'phone', 'status', 'user_type'])->all(),
                DB::table('providers')->orderBy('uuid')->get(['uuid', 'business_name', 'slug', 'verification_status', 'city'])->all(),
                DB::table('activities as a')->join('categories as c', 'c.id', '=', 'a.category_id')->orderBy('a.uuid')->get(['a.uuid', 'a.slug', 'a.status', 'c.slug as category', 'a.max_participants'])->all(),
                DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('customers as c', 'c.id', '=', 'r.customer_id')->join('users as u', 'u.id', '=', 'c.user_id')
                    ->orderBy('r.uuid')->get(['r.uuid', 'a.uuid as activity', 'u.phone as customer', 'r.status', 'r.reservation_date', 'r.total'])->all(),
                DB::table('payments')->orderBy('uuid')->get(['uuid', 'status', 'amount', 'refunded_amount'])->all(),
                DB::table('reviews')->orderBy('uuid')->get(['uuid', 'rating', 'comment'])->all(),
            ]));
        };

        $this->seed(BulkDatabaseSeeder::class);
        $first = $fingerprint();

        foreach (['notifications', 'otps', 'devices', 'favorites', 'review_replies', 'reviews', 'withdrawals', 'wallet_entries', 'coupon_redemptions', 'coupons', 'activity_views', 'payments', 'reservation_capacity_locks', 'reservations', 'activity_pricing_rules', 'activity_booking_options',
            'activity_availability_overrides', 'activity_schedule_periods', 'activity_options', 'activity_inclusions', 'activity_requirements', 'activity_feature_values', 'activity_images',
            'activities', 'provider_document_events', 'provider_documents', 'provider_profile_changes', 'provider_requests', 'providers', 'customers', 'admins', 'model_has_roles', 'broadcasts', 'users', 'feature_definitions', 'categories'] as $table) {
            DB::table($table)->delete();
        }
        // Back to a database as fresh as the first one, row ids included: prices and a few other choices derive from a row's id.
        DB::statement('delete from sqlite_sequence');
        $this->assertSame(0, DB::table('users')->count());
        $this->seed(BulkDatabaseSeeder::class);

        $this->assertSame($first, $fingerprint(), 'The same scale (and seed) must always produce identical data');

        Carbon::setTestNow();
    }

    // --- Safety ----------------------------------------------------------------------------------------------------

    public function test_they_refuse_to_run_in_production(): void
    {
        $this->baseData();
        $this->app->detectEnvironment(fn () => 'production');

        foreach (array_merge([BulkDatabaseSeeder::class], BulkDatabaseSeeder::SEEDERS) as $seeder) {
            try {
                // Called directly: "php artisan db:seed" has a confirmation prompt of its own in production, a different guard.
                app($seeder)->run();
                $this->fail(class_basename($seeder).' ran in production');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('must not run in production', $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('users')->count(), 'Nothing may have been written');
    }

    public function test_the_ordinary_database_seeder_does_not_create_bulk_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('users')->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->count());
        $this->assertSame(0, DB::table('activities')->where('slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->count());
    }

    public function test_demo_data_and_bulk_data_live_side_by_side(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = DB::table('users')->count();
        $demoActivities = DB::table('activities')->count();

        $this->seed(BulkDatabaseSeeder::class);

        $this->assertGreaterThan($demo, DB::table('users')->count());
        $this->assertGreaterThan($demoActivities, DB::table('activities')->count());
        $this->assertSame(1, DB::table('users')->where('email', 'admin@marine-booking.test')->count(), 'The demo super admin is untouched');
        $this->assertSame(0, DB::table('users')->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->where('phone', 'like', '05%')->count());
        $this->assertSame([], DB::select('pragma foreign_key_check'));
    }

    /** A seeder that creates nothing, only to reach the base class's helpers. */
    private function helpers(): object
    {
        return new class extends BulkSeeder
        {
            protected function seed(): void {}

            public function scale(int $n): int { return $this->scaled($n); }

            public function stableChoice(string $key, float $percent): bool { return $this->stable($key, $percent); }

            public function start(int $seed): void { $this->startRandom($seed); }

            public function weightedChoice(array $weights): string { return $this->weighted($weights); }

            public function id(string $kind, int $key): string { return $this->uuid($kind, $key); }
        };
    }

    public function test_the_scale_multiplies_every_volume_and_never_drops_below_one(): void
    {
        $seeder = $this->helpers();

        foreach ([[1.0, 15000, 15000], [0.5, 15000, 7500], [0.1, 150000, 15000], [0.01, 600, 6], [0.0001, 40, 1], [0.00001, 25000, 3]] as [$scale, $base, $expected]) {
            config(['seeding.bulk_scale' => $scale]);

            $this->assertSame($expected, $seeder->scale($base), "scale {$scale} of {$base}");
        }
    }

    public function test_a_stable_choice_depends_only_on_its_key_and_is_fair(): void
    {
        $seeder = $this->helpers();

        // The same key always gives the same answer, however often it is asked.
        foreach (['review-1', 'review-2', 'device-77'] as $key) {
            $first = $seeder->stableChoice($key, 50);
            for ($i = 0; $i < 20; $i++) {
                $this->assertSame($first, $seeder->stableChoice($key, 50));
            }
        }

        // And over many keys it comes out near the percentage asked for.
        foreach ([5, 30, 70] as $percent) {
            $yes = 0;
            for ($i = 0; $i < 20000; $i++) {
                $yes += $seeder->stableChoice("fairness-{$i}", $percent) ? 1 : 0;
            }
            $this->assertEqualsWithDelta($percent, $yes / 200, 1.5, "{$percent}% came out as ".($yes / 200).'%');
        }
        $this->assertFalse($seeder->stableChoice('anything', 0));
        $this->assertTrue($seeder->stableChoice('anything', 100));
    }

    public function test_weighted_choices_follow_their_weights(): void
    {
        $seeder = $this->helpers();
        $seeder->start(12345);   // the seeder's own engine: PHP's global generator plays no part in what a seeder draws

        $seen = ['a' => 0, 'b' => 0, 'c' => 0];
        for ($i = 0; $i < 20000; $i++) {
            $seen[$seeder->weightedChoice(['a' => 70, 'b' => 25, 'c' => 5])]++;
        }

        $this->assertEqualsWithDelta(70, $seen['a'] / 200, 1.5);
        $this->assertEqualsWithDelta(25, $seen['b'] / 200, 1.5);
        $this->assertEqualsWithDelta(5, $seen['c'] / 200, 1.0);
    }

    public function test_generated_uuids_are_valid_and_stable_and_distinct_per_kind(): void
    {
        $seeder = $this->helpers();

        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid($seeder->id('user-customer', 7)));
        $this->assertSame($seeder->id('user-customer', 7), $seeder->id('user-customer', 7), 'The same kind and number is the same uuid on every run');
        $this->assertNotSame($seeder->id('user-customer', 7), $seeder->id('user-provider', 7));
        $this->assertNotSame($seeder->id('user-customer', 7), $seeder->id('user-customer', 8));
    }

    public function test_the_scale_is_documented_in_the_env_example(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('BULK_SEED_SCALE', $example);
        $this->assertStringContainsString('BULK_SEED_RANDOM', $example);
    }
}
