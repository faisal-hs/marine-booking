<?php

namespace Tests\Feature\Admin;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * `migrate`, `migrate:fresh` and `db:seed` from the admin API. They really rebuild the database, so each test gets a database file of its own (migrated, with the roles and one
 * super admin) and throws it away. Only the super admin may use any of it, and the destructive command is the hardest to reach.
 */
class SystemCommandsApiTest extends TestCase
{
    use BuildsProviderScenarios;

    private const PASSWORD = 's3cret-Passw0rd!';

    private const BASE = '/api/v1/admin/system';

    private string $file;

    private string $token;

    /** The limit is three a minute for every attempt (a mistyped password counts too), which is far fewer than these tests make: it is cleared before each call, except where the limit is the point. */
    private bool $clearLimit = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/testing/system-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite');
        @mkdir(dirname($this->file), 0777, true);
        touch($this->file);
        config(['database.connections.sqlite.database' => $this->file]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->makeAdmin('super_admin');
        $admin->forceFill(['password' => Hash::make(self::PASSWORD)])->save();
        $this->token = $this->tokenFor($admin, 'admin');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        @unlink($this->file);

        parent::tearDown();
    }

    private function lookAt(string $path, ?string $token = null)
    {
        $this->clearLimiter();

        return $this->asAccount($token ?? $this->token)->getJson(self::BASE.$path);
    }

    private function send(string $path, array $body = [], ?string $token = null)
    {
        $this->clearLimiter();

        return $this->asAccount($token ?? $this->token)->postJson(self::BASE.$path, $body);
    }

    private function clearLimiter(): void
    {
        if ($this->clearLimit) {
            $this->app['cache']->store('array')->flush();
            $this->app['cache']->flush();
        }
    }

    private function fresh(array $over = [])
    {
        return $this->send('/migrate-fresh', array_merge(['confirm' => 'migrate:fresh', 'password' => self::PASSWORD], $over));
    }

    private function migrationFiles(): int
    {
        return count(glob(database_path('migrations/*.php')));
    }

    private function inProduction(bool $switchedOn = false): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['system_commands.allow_migrate_fresh_in_production' => $switchedOn]);
    }

    // --- Who may --------------------------------------------------------------------------------------------------------

    public function test_only_the_super_admin_holds_the_three_permissions(): void
    {
        foreach (['system.migrate', 'system.migrate_fresh', 'system.seed'] as $permission) {
            $this->assertTrue(Role::findByName('super_admin')->hasPermissionTo($permission), $permission);
            $this->assertFalse(Role::findByName('admin')->hasPermissionTo($permission), "admin must not hold {$permission}");
        }
    }

    public function test_nobody_else_may_look_or_run_anything(): void
    {
        $tokens = [];
        foreach (['admin', 'content_admin', 'support_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $tokens[$role] = $this->tokenFor($this->makeAdmin($role), 'admin');
        }
        [$customer] = $this->makeCustomer();
        $tokens['customer'] = $this->tokenFor($customer, 'customer');
        $tokens['provider'] = $this->tokenFor($customer, 'provider');
        $marker = DB::table('users')->count();

        foreach ($tokens as $who => $token) {
            foreach (['/migrations', '/seeders'] as $path) {
                $this->lookAt($path, $token)->assertForbidden();
            }
            foreach (['/migrate' => [], '/migrate-fresh' => ['confirm' => 'migrate:fresh', 'password' => 'x'], '/seed' => ['class' => 'CitySeeder']] as $path => $body) {
                $this->send($path, $body, $token)->assertForbidden();
            }
        }

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::BASE.'/migrations')->assertUnauthorized();
        $this->postJson(self::BASE.'/migrate-fresh', [])->assertUnauthorized();
        $this->assertSame($marker, DB::table('users')->count(), 'Nothing was run');
    }

    public function test_each_command_is_behind_its_own_permission(): void
    {
        $ops = Role::create(['name' => 'ops', 'guard_name' => 'web']);
        $ops->givePermissionTo('system.migrate');
        $token = $this->tokenFor($this->makeAdmin('ops'), 'admin');

        $this->lookAt('/migrations', $token)->assertOk();
        $this->send('/migrate', [], $token)->assertOk();
        $this->send('/migrate-fresh', ['confirm' => 'migrate:fresh', 'password' => 'x'], $token)->assertForbidden();
        $this->send('/seed', ['class' => 'CitySeeder'], $token)->assertForbidden();
    }

    // --- migrate -------------------------------------------------------------------------------------------------------------

    public function test_the_status_lists_what_is_pending(): void
    {
        $ran = $this->lookAt('/migrations')->assertOk();
        $this->assertSame(['environment', 'ran', 'pending', 'output'][0], array_keys($ran->json('data'))[0]);
        $this->assertSame([], $ran->json('data.pending'));
        $this->assertSame($this->migrationFiles(), $ran->json('data.ran'));

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $after = $this->lookAt('/migrations')->json('data');
        $this->assertCount(1, $after['pending']);
        $this->assertSame($this->migrationFiles() - 1, $after['ran']);
    }

    public function test_migrate_runs_what_is_pending_says_so_and_is_safe_to_repeat(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $pending = $this->lookAt('/migrations')->json('data.pending');

        $r = $this->send('/migrate')->assertOk();

        $this->assertSame($pending, $r->json('data.ran'));
        $this->assertSame([], $r->json('data.pending'));
        $this->assertSame('Migrations run.', $r->json('message'));
        $this->assertNotEmpty(array_filter($r->json('data.output'), fn ($line) => str_contains($line, 'notification_preferences')), 'What the command said is passed on');
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('providers', 'notify_whatsapp'), 'The migration really ran');

        $again = $this->send('/migrate')->assertOk();
        $this->assertSame([], $again->json('data.ran'));
        $this->assertSame($this->migrationFiles(), DB::table('migrations')->count());
    }

    // --- migrate:fresh ------------------------------------------------------------------------------------------------------

    public function test_it_wants_the_phrase_and_the_admins_own_password_and_otherwise_touches_nothing(): void
    {
        $before = DB::table('users')->count();
        $this->assertGreaterThan(0, $before);

        $this->fresh(['confirm' => 'yes please'])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->fresh(['confirm' => null])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->fresh(['password' => 'not-my-password'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->fresh(['password' => null])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->send('/migrate-fresh', [])->assertStatus(422);

        $this->assertSame($before, DB::table('users')->count(), 'Every refusal left the data as it was');
    }

    public function test_it_drops_everything_and_migrates_again_and_says_the_database_is_empty(): void
    {
        $r = $this->fresh()->assertOk();

        $this->assertSame([true, true, null], [$r->json('data.empty'), $r->json('data.signed_out'), $r->json('data.seeded_with')]);
        $this->assertStringContainsString('empty', $r->json('message'));
        $this->assertSame(0, DB::table('users')->count(), 'Every table was dropped and made again');
        $this->assertSame($this->migrationFiles(), DB::table('migrations')->count());
        $this->assertNotEmpty($r->json('data.output'));
    }

    public function test_it_can_seed_afterwards_with_a_seeder_from_the_list(): void
    {
        $r = $this->fresh(['seed' => true, 'seeder' => 'RolesAndPermissionsSeeder'])->assertOk();

        $this->assertSame(['RolesAndPermissionsSeeder', false], [$r->json('data.seeded_with'), $r->json('data.empty')]);
        $this->assertTrue(DB::table('roles')->where('name', 'super_admin')->exists());
        $this->assertSame(0, DB::table('users')->count(), 'Only the roles');
    }

    public function test_by_default_it_seeds_with_the_database_seeder_which_makes_the_demo_accounts_outside_production(): void
    {
        $r = $this->fresh(['seed' => 'true'])->assertOk();

        $this->assertSame('DatabaseSeeder', $r->json('data.seeded_with'));
        $this->assertTrue(DB::table('users')->where('email', 'admin@marine-booking.test')->exists());
    }

    public function test_a_seeder_without_asking_to_seed_is_ignored(): void
    {
        $r = $this->fresh(['seeder' => 'RolesAndPermissionsSeeder'])->assertOk();

        $this->assertNull($r->json('data.seeded_with'));
        $this->assertSame(0, DB::table('roles')->count());
    }

    public function test_only_a_listed_seeder_may_be_named_and_a_refusal_touches_nothing(): void
    {
        $before = DB::table('users')->count();

        foreach (['BulkDatabaseSeeder', 'Bulk\\BulkUserSeeder', '../../Evil', 'App\\Models\\User', 'database/seeders/DatabaseSeeder'] as $bad) {
            $this->fresh(['seed' => true, 'seeder' => $bad])->assertStatus(422)->assertJsonValidationErrors('seeder');
        }

        $this->assertSame($before, DB::table('users')->count());
    }

    public function test_in_production_it_is_refused_unless_switched_on_and_touches_nothing(): void
    {
        $this->inProduction();
        $before = DB::table('users')->count();

        $r = $this->fresh()->assertForbidden();

        $this->assertSame(['MIGRATE_FRESH_DISABLED_IN_PRODUCTION', false], [$r->json('data.code'), $r->json('success')]);
        $this->assertStringContainsString('SYSTEM_COMMANDS_ALLOW_MIGRATE_FRESH_IN_PRODUCTION', $r->json('message'));
        $this->assertSame($before, DB::table('users')->count());
    }

    public function test_in_production_when_switched_on_it_still_needs_the_phrase_and_the_password(): void
    {
        $this->inProduction(true);
        $before = DB::table('users')->count();

        $this->fresh(['confirm' => 'no'])->assertStatus(422);
        $this->fresh(['password' => 'wrong'])->assertStatus(422);
        $this->assertSame($before, DB::table('users')->count());

        $this->fresh(['seed' => true, 'seeder' => 'RolesAndPermissionsSeeder'])->assertOk();
        $this->assertSame(0, DB::table('users')->count());
        $this->assertTrue(DB::table('roles')->where('name', 'super_admin')->exists());
    }

    public function test_in_production_even_switched_on_it_will_not_seed_demo_data_and_touches_nothing(): void
    {
        $this->inProduction(true);
        $before = DB::table('users')->count();

        foreach (['DemoUsersSeeder', 'AdminSeeder'] as $demo) {
            $r = $this->fresh(['seed' => true, 'seeder' => $demo])->assertForbidden();

            $this->assertSame('SEEDER_NOT_ALLOWED_IN_PRODUCTION', $r->json('data.code'));
        }
        $this->assertSame($before, DB::table('users')->count(), 'Refused before anything was dropped');
    }

    // --- db:seed ---------------------------------------------------------------------------------------------------------------

    public function test_the_list_says_which_seeders_may_run_here(): void
    {
        $r = $this->lookAt('/seeders')->assertOk();
        $byName = collect($r->json('data.seeders'))->keyBy('class');

        $this->assertSame(['class', 'kind', 'allowed_here'], array_keys($byName['CitySeeder']));
        $this->assertSame(['safe', true], [$byName['CitySeeder']['kind'], $byName['CitySeeder']['allowed_here']]);
        $this->assertSame(['demo', true], [$byName['DemoUsersSeeder']['kind'], $byName['DemoUsersSeeder']['allowed_here']], 'Outside production');
        $this->assertArrayNotHasKey('BulkDatabaseSeeder', $byName->all());

        $this->inProduction();
        $inProduction = collect($this->lookAt('/seeders')->json('data.seeders'))->keyBy('class');
        $this->assertSame([true, false], [$inProduction['CitySeeder']['allowed_here'], $inProduction['DemoUsersSeeder']['allowed_here']]);
    }

    public function test_a_listed_seeder_runs_and_its_output_comes_back_and_it_is_safe_to_repeat(): void
    {
        $r = $this->send('/seed', ['class' => 'CitySeeder'])->assertOk();

        $this->assertSame(['CitySeeder', 'CitySeeder was run.'], [$r->json('data.class'), $r->json('message')]);
        $cities = DB::table('cities')->count();
        $this->assertGreaterThan(0, $cities);

        $this->send('/seed', ['class' => 'CitySeeder'])->assertOk();
        $this->assertSame($cities, DB::table('cities')->count());
    }

    public function test_demo_seeders_run_outside_production(): void
    {
        $this->send('/seed', ['class' => 'DemoUsersSeeder'])->assertOk();

        $this->assertTrue(DB::table('users')->where('email', 'provider@marine-booking.test')->exists());
    }

    public function test_in_production_a_demo_seeder_is_refused_and_a_safe_one_runs(): void
    {
        $this->inProduction();

        $r = $this->send('/seed', ['class' => 'DemoUsersSeeder'])->assertForbidden();
        $this->assertSame('SEEDER_NOT_ALLOWED_IN_PRODUCTION', $r->json('data.code'));
        $this->assertFalse(DB::table('users')->where('email', 'provider@marine-booking.test')->exists());

        $this->send('/seed', ['class' => 'CitySeeder'])->assertOk();
        $this->assertGreaterThan(0, DB::table('cities')->count());
    }

    public function test_nothing_but_a_listed_name_can_be_seeded(): void
    {
        foreach (['BulkDatabaseSeeder', 'Database\\Seeders\\CitySeeder', '../Evil', 'Illuminate\\Database\\Seeder', '', 'citySeeder'] as $bad) {
            $this->send('/seed', ['class' => $bad])->assertStatus(422)->assertJsonValidationErrors('class');
        }
        $this->send('/seed', [])->assertStatus(422);
        $this->assertSame(0, DB::table('cities')->count());
    }

    // --- Failure, one at a time, accountability --------------------------------------------------------------------------------

    public function test_a_seeder_that_fails_is_an_error_answer_and_the_next_command_can_run(): void
    {
        class_alias(get_class(new class extends Seeder
        {
            public function run(): void
            {
                throw new \RuntimeException('boom from the seeder');
            }
        }), 'Database\\Seeders\\ExplodingTestSeeder');
        config(['system_commands.seeders.safe' => ['ExplodingTestSeeder', 'CitySeeder']]);

        $r = $this->send('/seed', ['class' => 'ExplodingTestSeeder'])->assertStatus(500);

        $this->assertFalse($r->json('success'));
        $this->assertSame('COMMAND_FAILED', $r->json('data.code'));
        $this->assertStringContainsString('boom from the seeder', $r->json('message'));

        $this->send('/seed', ['class' => 'CitySeeder'])->assertOk();   // the lock was let go
    }

    public function test_only_one_command_runs_at_a_time(): void
    {
        $held = fopen(storage_path('framework/system-command.lock'), 'c');
        flock($held, LOCK_EX | LOCK_NB);

        $r = $this->send('/migrate')->assertStatus(409);
        $this->assertSame('ANOTHER_COMMAND_IS_RUNNING', $r->json('data.code'));
        $this->send('/seed', ['class' => 'CitySeeder'])->assertStatus(409);
        $this->fresh()->assertStatus(409);
        $this->assertGreaterThan(0, DB::table('users')->count(), 'The busy refusal dropped nothing');

        flock($held, LOCK_UN);
        fclose($held);
        $this->send('/migrate')->assertOk();
    }

    public function test_every_run_is_logged_to_the_log_with_who_ran_it(): void
    {
        Log::spy();

        $this->fresh(['seed' => true, 'seeder' => 'RolesAndPermissionsSeeder'])->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'migrate:fresh') && isset($context['user_id']) && ($context['seeder'] ?? null) === 'RolesAndPermissionsSeeder')->once();
    }

    public function test_migrate_fresh_is_limited_to_three_attempts_a_minute_and_a_refused_one_counts(): void
    {
        $this->clearLimit = false;

        // A mistyped password is an attempt: three of them and the fourth is turned away before it is even looked at.
        foreach (range(1, 3) as $i) {
            $this->fresh(['password' => 'wrong'])->assertStatus(422);
        }
        $this->fresh()->assertStatus(429);
        $this->assertGreaterThan(0, DB::table('users')->count(), 'Nothing was dropped');
    }

    public function test_the_other_commands_are_limited_to_ten_a_minute_and_have_a_budget_of_their_own(): void
    {
        $this->clearLimit = false;

        foreach (range(1, 10) as $i) {
            $this->send('/migrate')->assertOk();
        }
        $this->send('/migrate')->assertStatus(429);
        $this->send('/seed', ['class' => 'CitySeeder'])->assertStatus(429);   // seeding shares the budget of the other commands

        $this->lookAt('/migrations')->assertOk();                              // looking is not limited
        $this->fresh(['password' => 'wrong'])->assertStatus(422);              // and the destructive one has its own, untouched
    }
}
