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
use App\Services\PaymentService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentVisibilityTest extends TestCase
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

    protected function makePaidPayment(array $simulate = ['simulate' => 'sync_success']): Payment
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

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);
        $result = app(PaymentService::class)->initiate($reservation, $simulate);

        return $result['payment'];
    }

    public function test_admin_can_list_payments(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/payments');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_view_payment_details_including_gateway_internals(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/payments/{$payment->uuid}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonStructure(['data' => [
                'id',
                'uuid',
                'reference_id',
                'reservation',
                'customer',
                'activity',
                'gateway',
                'payment_id',
                'provider_reference',
                'status',
                'amount',
                'currency',
                'failure_reason',
                'refunded_amount',
                'paid_at',
                'raw_payload',
                'created_at',
                'updated_at',
            ]]);

        $this->assertNotNull($response->json('data.payment_id'));
    }

    public function test_admin_without_permission_cannot_list_payments(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/payments');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/payments');

        $response->assertStatus(401);
    }

    public function test_filter_by_status(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makePaidPayment(['simulate' => 'sync_success']);
        $this->makePaidPayment(['simulate' => 'sync_failure']);

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/payments?status=failed');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'failed');
    }

    public function test_search_by_reference_id(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();
        $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/payments?search={$payment->reference_id}");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $payment->id);
    }

    // --- Refunds ---

    public function test_admin_with_refund_permission_can_refund(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->postJson("/api/v1/admin/payments/{$payment->uuid}/refund", []);

        $response->assertOk()->assertJsonPath('data.status', 'refunded');
    }

    public function test_admin_can_issue_a_partial_refund(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')
            ->postJson("/api/v1/admin/payments/{$payment->uuid}/refund", ['amount' => 500]);

        $response->assertOk()->assertJsonPath('data.status', 'partially_refunded');
    }

    public function test_support_admin_has_view_but_not_refund_permission(): void
    {
        // Confirms the deliberate split: support_admin has payments.view
        // (per the existing seeder) but NOT payments.refund (a new,
        // more restrictive permission added specifically for this).
        $admin = $this->makeAdmin('support_admin');
        $payment = $this->makePaidPayment();

        $viewResponse = $this->actingAs($admin, 'web')->getJson("/api/v1/admin/payments/{$payment->uuid}");
        $viewResponse->assertOk();

        $refundResponse = $this->actingAs($admin, 'web')->postJson("/api/v1/admin/payments/{$payment->uuid}/refund", []);
        $refundResponse->assertStatus(403);
    }

    public function test_no_update_or_delete_routes_exist_for_admin_payments(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $this->actingAs($admin, 'web')->putJson("/api/v1/admin/payments/{$payment->uuid}", [])->assertStatus(405);
        $this->actingAs($admin, 'web')->deleteJson("/api/v1/admin/payments/{$payment->uuid}")->assertStatus(405);
    }
}
