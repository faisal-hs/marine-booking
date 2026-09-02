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

class ReservationVisibilityTest extends TestCase
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
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'published',
        ]);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
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

    // --- Basic listing and detail ---

    public function test_admin_can_list_reservations(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_view_reservation_details(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation();
        $period = $reservation->activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $reservation->update(['schedule_period_id' => $period->id]);

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations/{$reservation->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.pricing_model', 'per_person')
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonStructure(['data' => [
                'id',
                'uuid',
                'activity',
                'booking_option',
                'customer',
                'schedule_period',
                'reservation_date',
                'participants',
                'quantity',
                'duration',
                'status',
                'hold_expires_at',
                'pricing_model',
                'currency',
                'subtotal',
                'discount',
                'tax',
                'total',
                'pricing_breakdown',
                'confirmed_at',
                'cancelled_at',
                'cancellation_reason',
                'created_at',
                'updated_at',
            ]]);

        $this->assertNotNull($response->json('data.customer.email'));
    }

    public function test_pagination_works(): void
    {
        $admin = $this->makeAdmin('super_admin');
        for ($i = 0; $i < 3; $i++) {
            $this->makeReservation();
        }

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations?per_page=2&current_page=1');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total_items', 3)
            ->assertJsonPath('pagination.per_page', 2);
    }

    // --- Filters ---

    public function test_filter_by_status(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReservation(['status' => 'confirmed']);
        $this->makeReservation(['status' => 'cancelled']);

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations?status=cancelled');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_filter_by_activity(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservationA = $this->makeReservation();
        $this->makeReservation(); // different activity entirely

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations?activity={$reservationA->activity->uuid}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservationA->id);
    }

    public function test_filter_by_booking_option(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservationA = $this->makeReservation();
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations?booking_option={$reservationA->bookingOption->uuid}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservationA->id);
    }

    public function test_filter_by_customer(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservationA = $this->makeReservation();
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations?customer={$reservationA->customer->uuid}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservationA->id);
    }

    public function test_filter_by_exact_date(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReservation(['reservation_date' => '2026-09-05']);
        $this->makeReservation(['reservation_date' => '2026-09-10']);

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations?date=2026-09-10');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reservation_date', '2026-09-10');
    }

    public function test_filter_by_date_range(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makeReservation(['reservation_date' => '2026-09-01']);
        $this->makeReservation(['reservation_date' => '2026-09-10']);
        $this->makeReservation(['reservation_date' => '2026-09-20']);

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations?date_from=2026-09-05&date_to=2026-09-15');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reservation_date', '2026-09-10');
    }

    // --- Search ---

    public function test_search_by_uuid(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation();
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations?search={$reservation->uuid}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservation->id);
    }

    public function test_search_by_id(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation();
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations?search={$reservation->id}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $reservation->id);
    }

    // --- Sorting ---

    public function test_sort_newest_first_by_default(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $older = $this->makeReservation();
        $newer = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations');

        $response->assertOk()->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_sort_oldest_first_when_requested(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $older = $this->makeReservation();
        $newer = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations?sort=oldest');

        $response->assertOk()->assertJsonPath('data.0.id', $older->id);
    }

    // --- Authorization ---

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/reservations');

        $response->assertStatus(401);
    }

    public function test_admin_without_reservations_permission_cannot_list(): void
    {
        // content_admin does NOT have reservations.view in the seeder.
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations');

        $response->assertStatus(403);
    }

    public function test_admin_without_reservations_permission_cannot_view_details(): void
    {
        $admin = $this->makeAdmin('content_admin');
        $reservation = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations/{$reservation->uuid}");

        $response->assertStatus(403);
    }

    public function test_support_admin_can_view_per_existing_permission_grant(): void
    {
        // support_admin DOES have reservations.view per the existing seeder.
        $admin = $this->makeAdmin('support_admin');
        $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations');

        $response->assertOk();
    }

    // --- Read-only enforcement ---

    public function test_no_create_route_exists_for_admin_reservations(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $response = $this->actingAs($admin, 'web')->postJson('/api/v1/admin/reservations', []);

        $response->assertStatus(405);
    }

    public function test_no_update_route_exists_for_admin_reservations(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->putJson("/api/v1/admin/reservations/{$reservation->uuid}", []);

        $response->assertStatus(405);
    }

    public function test_no_delete_route_exists_for_admin_reservations(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation();

        $response = $this->actingAs($admin, 'web')->deleteJson("/api/v1/admin/reservations/{$reservation->uuid}");

        $response->assertStatus(405);
    }

    public function test_viewing_a_reservation_through_admin_api_does_not_change_its_status(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $reservation = $this->makeReservation(['status' => 'pending']);

        $this->actingAs($admin, 'web')->getJson("/api/v1/admin/reservations/{$reservation->uuid}");
        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/reservations');

        $this->assertEquals('pending', $reservation->fresh()->status->value);
    }
}
