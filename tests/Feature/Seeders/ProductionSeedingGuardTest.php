<?php

namespace Tests\Feature\Seeders;

use App\Models\Activity;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\AdminRoleUsersSeeder;
use Database\Seeders\AdminSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\FeatureDefinitionSeeder;
use Database\Seeders\ProviderSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * AdminSeeder creates a super admin whose password is "password", and
 * `php artisan db:seed` ran it. Seeding a live database therefore handed out
 * a working admin login.
 *
 * The demo seeders now refuse to run in production. The roles and permissions
 * the admin panel needs are still seeded there, and everything keeps working
 * in local development and in the test suite.
 */
class ProductionSeedingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function inProduction(): void
    {
        $this->app['env'] = 'production';
    }

    /** @return array<string, array{0: class-string}> */
    public static function demoSeeders(): array
    {
        return [
            'admin with a known password' => [AdminSeeder::class],
            'demo admins per role' => [AdminRoleUsersSeeder::class],
            'demo provider and customer' => [DemoUsersSeeder::class],
            'fake providers' => [ProviderSeeder::class],
            'fake customers' => [CustomerSeeder::class],
            'sample activities' => [ActivitySeeder::class],
        ];
    }

    #[DataProvider('demoSeeders')]
    public function test_a_demo_seeder_refuses_to_run_in_production(string $seederClass): void
    {
        $this->inProduction();

        try {
            (new $seederClass())->run();
            $this->fail("{$seederClass} should have refused to run in production.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(class_basename($seederClass), $e->getMessage());
            $this->assertStringContainsString('production', $e->getMessage());
        }

        $this->assertSame(0, User::count(), 'A refused seeder must create nothing.');
    }

    public function test_the_roles_and_permissions_seeder_still_runs_in_production(): void
    {
        $this->inProduction();

        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, Role::count());
        $this->assertGreaterThan(0, Permission::count());
        $this->assertSame(0, User::count(), 'No accounts may be created by the roles seeder.');
    }

    public function test_the_default_db_seed_is_safe_in_production_and_seeds_only_roles_and_permissions(): void
    {
        $this->inProduction();

        $this->artisan('db:seed', ['--force' => true])
            ->expectsOutputToContain('Production: seeded roles and permissions only')
            ->assertSuccessful();

        $this->assertGreaterThan(0, Role::count());
        $this->assertGreaterThan(0, Permission::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, Category::count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@marine-booking.test']);
    }

    public function test_the_reference_data_seeders_are_not_guarded(): void
    {
        $this->inProduction();

        $this->artisan('db:seed', ['--class' => CategorySeeder::class, '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => FeatureDefinitionSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, Category::count());
        $this->assertSame(0, User::count());
    }

    public function test_the_full_demo_data_is_still_seeded_in_local_development(): void
    {
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@marine-booking.test']);
        $this->assertDatabaseHas('users', ['email' => 'provider@marine-booking.test']);
        $this->assertGreaterThan(0, Activity::count());
        $this->assertGreaterThan(0, Category::count());
    }

    public function test_the_demo_seeders_still_work_in_the_testing_environment(): void
    {
        $this->assertTrue($this->app->environment('testing'));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdminSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@marine-booking.test']);
    }
}
