<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role = 'super_admin'): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function seedSomeData(): void
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $reservation = Reservation::create([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->toDateString(),
            'status' => 'confirmed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 1000,
            'total' => 1000,
            'pricing_breakdown' => [],
        ]);

        Payment::create([
            'reservation_id' => $reservation->id,
            'gateway' => 'mock',
            'amount' => 1000,
            'currency' => 'EGP',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);
    }

    public function test_admin_can_view_the_dashboard(): void
    {
        $admin = $this->makeAdmin();
        $this->seedSomeData();

        $response = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Test Activity')
            ->assertSee('1,000.00');
    }

    public function test_dashboard_shows_zero_states_when_no_data_exists(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));

        $response->assertOk();
    }

    public function test_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_json_api_dashboard_returns_stats(): void
    {
        $admin = $this->makeAdmin();
        $this->seedSomeData();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.reservations.total', 1)
            ->assertJsonStructure(['data' => ['stats' => [
                'users',
                'providers',
                'activities',
                'reservations',
                'payments',
                'reviews',
            ]]]);

        $this->assertEquals(1000.0, $response->json('data.stats.payments.net_revenue'));
    }

    public function test_json_api_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(401);
    }

    public function test_dashboard_is_available_to_any_authenticated_admin_role(): void
    {
        // Matches the existing dashboard's own precedent: every admin
        // role can see the overview numbers; only specific QUICK-ACTION
        // links are gated by permission, not the page itself.
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));

        $response->assertOk();
    }
}
