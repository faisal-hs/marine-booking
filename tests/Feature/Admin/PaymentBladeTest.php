<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentBladeTest extends TestCase
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

    protected function makePaidPayment(): Payment
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

        $reservation = app(ReservationService::class)->create($customer, $activity, $option, null, Carbon::parse('2026-09-05'), ['adult' => 1]);
        $result = app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success']);

        return $result['payment'];
    }

    public function test_admin_with_permission_can_view_payments_index_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->get(route('admin.payments.index'));

        $response->assertOk()->assertSee('Jane Customer');
    }

    public function test_admin_with_permission_can_view_payment_show_page(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->get(route('admin.payments.show', $payment));

        $response->assertOk()->assertSee($payment->reference_id)->assertSee('1,500.00');
    }

    public function test_admin_without_permission_gets_403(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.payments.index'));

        $response->assertStatus(403);
    }

    public function test_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.payments.index'));

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_with_refund_permission_sees_refund_form(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->get(route('admin.payments.show', $payment));

        $response->assertOk()->assertSee(route('admin.payments.refund', $payment), false);
    }

    public function test_support_admin_does_not_see_refund_form(): void
    {
        $admin = $this->makeAdmin('support_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->get(route('admin.payments.show', $payment));

        $response->assertOk()->assertDontSee(route('admin.payments.refund', $payment), false);
    }

    public function test_admin_can_submit_refund_via_the_form(): void
    {
        $admin = $this->makeAdmin('super_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->post(route('admin.payments.refund', $payment));

        $response->assertRedirect();
        $this->assertEquals('refunded', $payment->fresh()->status->value);
    }

    public function test_support_admin_cannot_submit_refund_directly(): void
    {
        $admin = $this->makeAdmin('support_admin');
        $payment = $this->makePaidPayment();

        $response = $this->actingAs($admin, 'web')->post(route('admin.payments.refund', $payment));

        $response->assertStatus(403);
        $this->assertEquals('succeeded', $payment->fresh()->status->value);
    }
}
