<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationBladeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function makeReservation(array $overrides = []): Reservation
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Diving Trip',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create(['name' => 'Jane Customer']);
        $customer = Customer::create(['user_id' => $customerUser->id]);

        return Reservation::create(array_merge([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => '2026-09-05',
            'participants' => ['adult' => 2],
            'status' => 'confirmed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 3000,
            'total' => 3000,
            'pricing_breakdown' => [],
        ], $overrides));
    }

    public function test_admin_with_permission_can_view_reservations_index_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reservations.index'));

        $response->assertOk()
            ->assertSee('Test Diving Trip')
            ->assertSee('Jane Customer');
    }

    public function test_admin_with_permission_can_view_reservation_show_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reservations.show', $reservation));

        $response->assertOk()
            ->assertSee('Test Diving Trip')
            ->assertSee('Jane Customer')
            ->assertSee('3,000.00');
    }

    public function test_admin_without_permission_gets_403_on_index(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.reservations.index'));

        $response->assertStatus(403);
    }

    public function test_admin_without_permission_gets_403_on_show(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $reservation = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reservations.show', $reservation));

        $response->assertStatus(403);
    }

    public function test_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.reservations.index'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_status_filter_works_on_index_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReservation(['status' => 'confirmed']);
        $this->makeReservation(['status' => 'cancelled']);

        $response = $this->actingAs($admin, 'web')->get(route('admin.reservations.index', ['status' => 'cancelled']));

        $response->assertOk();
        $this->assertEquals(1, substr_count($response->getContent(), 'text-slate-500">#'));
    }

    public function test_reservations_nav_link_is_active_once_permission_granted(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.dashboard'));

        $response->assertOk()->assertSee(route('admin.reservations.index'), false);
    }
}
