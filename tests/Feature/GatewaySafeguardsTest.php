<?php

namespace Tests\Feature;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Contracts\Payments\PaymentGatewayInterface;
use App\Enums\ReservationStatus;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Fcm\HttpFcmGateway;
use App\Services\Fcm\LogFcmGateway;
use App\Services\Payments\MockPaymentGateway;
use App\Services\Payments\UnavailablePaymentGateway;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\TaqnyatSmsGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

/**
 * The three driver settings (PAYMENT_GATEWAY, SMS_DRIVER, FCM_DRIVER) used to
 * fail OPEN: an unrecognised value silently selected a real or fake service.
 *
 *  - SMS_DRIVER=lgo       -> the real Taqnyat gateway
 *  - FCM_DRIVER=lgo       -> the real FCM service
 *  - PAYMENT_GATEWAY=anything, and the mock in production -> the mock gateway,
 *    which reports every payment as paid without charging anyone
 *
 * They now fail closed. The mock stays available everywhere except production.
 */
class GatewaySafeguardsTest extends TestCase
{
    use RefreshDatabase;
    use RelativeDates;

    private function inProduction(): void
    {
        $this->app['env'] = 'production';
    }

    /** @return array<string, array{0: string}> */
    public static function unknownDrivers(): array
    {
        return [
            'a typo' => ['lgo'],
            'wrong case' => ['LOG'],
            'an empty value' => [''],
            'another provider' => ['twilio'],
        ];
    }

    // --- SMS ---------------------------------------------------------------

    public function test_the_log_sms_driver_resolves_the_log_gateway(): void
    {
        config(['services.sms.driver' => 'log']);

        $this->assertInstanceOf(LogSmsGateway::class, app(SmsGatewayInterface::class));
    }

    public function test_the_taqnyat_sms_driver_resolves_the_real_gateway(): void
    {
        config(['services.sms.driver' => 'taqnyat']);

        $this->assertInstanceOf(TaqnyatSmsGateway::class, app(SmsGatewayInterface::class));
    }

    #[DataProvider('unknownDrivers')]
    public function test_an_unknown_sms_driver_is_rejected_instead_of_selecting_the_real_gateway(string $driver): void
    {
        config(['services.sms.driver' => $driver]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('log, taqnyat');

        app(SmsGatewayInterface::class);
    }

    public function test_a_misspelt_sms_driver_sends_nothing_to_the_real_service(): void
    {
        // The end-to-end version of the rule above: before the fix, "lgo" selected
        // Taqnyat and this OTP request would have called its HTTP API.
        Http::fake();
        config(['services.sms.driver' => 'lgo']);

        $response = $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0522222222']);

        $response->assertStatus(500);
        Http::assertNothingSent();
    }

    // --- FCM ---------------------------------------------------------------

    public function test_the_log_fcm_driver_resolves_the_log_gateway(): void
    {
        config(['services.fcm.driver' => 'log']);

        $this->assertInstanceOf(LogFcmGateway::class, app(FcmGatewayInterface::class));
    }

    public function test_the_http_fcm_driver_resolves_the_real_gateway(): void
    {
        config(['services.fcm.driver' => 'http']);

        $this->assertInstanceOf(HttpFcmGateway::class, app(FcmGatewayInterface::class));
    }

    #[DataProvider('unknownDrivers')]
    public function test_an_unknown_fcm_driver_is_rejected_instead_of_selecting_the_real_gateway(string $driver): void
    {
        config(['services.fcm.driver' => $driver]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('log, http');

        app(FcmGatewayInterface::class);
    }

    // --- Payments: choosing the gateway -------------------------------------

    #[DataProvider('nonProductionEnvironments')]
    public function test_the_mock_gateway_works_outside_production(string $environment): void
    {
        $this->app['env'] = $environment;
        config(['payments.default' => 'mock']);

        $this->assertInstanceOf(MockPaymentGateway::class, app(PaymentGatewayInterface::class));
    }

    /** @return array<string, array{0: string}> */
    public static function nonProductionEnvironments(): array
    {
        return ['local' => ['local'], 'testing' => ['testing'], 'staging' => ['staging']];
    }

    public function test_the_mock_gateway_is_replaced_by_a_refusing_gateway_in_production(): void
    {
        $this->inProduction();
        config(['payments.default' => 'mock']);

        $this->assertInstanceOf(UnavailablePaymentGateway::class, app(PaymentGatewayInterface::class));
    }

    public function test_an_unknown_payment_gateway_refuses_instead_of_falling_back_to_the_mock(): void
    {
        config(['payments.default' => 'stripe']);

        $this->assertInstanceOf(UnavailablePaymentGateway::class, app(PaymentGatewayInterface::class));
    }

    public function test_the_refusing_gateway_refuses_every_operation_and_logs_the_real_reason(): void
    {
        Log::spy();
        $this->inProduction();
        $gateway = app(PaymentGatewayInterface::class);

        foreach (
            [
                'charge' => fn() => $gateway->charge(new Payment()),
                'refund' => fn() => $gateway->refund(new Payment()),
                'webhook' => fn() => $gateway->handleWebhook(Request::create('/webhook', 'POST')),
            ] as $operation => $attempt
        ) {
            try {
                $attempt();
                $this->fail("The {$operation} should have been refused.");
            } catch (PaymentGatewayUnavailableException $e) {
                $this->assertSame(503, $e->getStatusCode());
                // Generic on purpose: this message can reach a customer.
                $this->assertSame('Online payments are currently unavailable.', $e->getMessage());
            }
        }

        Log::shouldHaveReceived('critical')->times(3)->withArgs(
            fn(string $message) => str_contains($message, 'mock') && str_contains($message, 'production')
        );
    }

    public function test_the_unknown_gateway_name_is_logged_so_the_cause_is_findable(): void
    {
        Log::spy();
        config(['payments.default' => 'stripe']);

        try {
            app(PaymentGatewayInterface::class)->charge(new Payment());
            $this->fail('The charge should have been refused.');
        } catch (PaymentGatewayUnavailableException) {
            Log::shouldHaveReceived('critical')->once()->withArgs(fn(string $message) => str_contains($message, 'stripe'));
        }
    }

    // --- Payments: what customers and admins see in production --------------

    /** @return array{0: string, 1: Reservation} [bearer token, pending reservation] */
    private function makeCustomerWithReservation(): array
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
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        $customerUser = User::factory()->customer()->create();
        Customer::create(['user_id' => $customerUser->id]);
        $token = $customerUser->createToken('test', ['customer'])->plainTextToken;

        $created = $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/v1/customer/reservations', [
            'activity' => $activity->uuid,
            'booking_option' => $option->uuid,
            'reservation_date' => $this->futureSaturday()->toDateString(),
            'participants' => ['adult' => 2],
        ]);

        return [$token, Reservation::findOrFail($created->json('data.id'))];
    }

    public function test_in_production_a_customer_cannot_pay_even_when_asking_for_a_simulated_success(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();
        $this->inProduction();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'sync_success']);

        $response->assertStatus(503)->assertJson([
            'success' => false,
            'message' => 'Online payments are currently unavailable.',
        ]);

        // Nothing was marked as paid, and the refused attempt left no orphan pending payment behind.
        $this->assertEquals(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_in_production_the_payment_webhook_is_refused(): void
    {
        $this->inProduction();

        $response = $this->postJson('/api/v1/webhooks/payments/mock', ['provider_reference' => 'mock_x', 'status' => 'succeeded']);

        $response->assertStatus(503);
        $this->assertSame(0, Payment::count());
    }

    public function test_admin_payment_pages_still_load_in_production(): void
    {
        // The admin controllers have PaymentService (and so the gateway) injected into
        // their constructors. The refusal must wait until money would move, otherwise
        // simply opening these pages would fail.
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();
        Admin::create(['user_id' => $admin->id, 'job_title' => 'Test']);
        $admin->syncRoles(['super_admin']);
        $this->inProduction();

        $this->actingAs($admin, 'web')->get('/admin/payments')->assertOk();
        $this->actingAs($admin, 'web')->get('/admin/dashboard')->assertOk();
        $this->actingAs($admin, 'web')->getJson('/api/v1/admin/payments')->assertOk();
    }

    public function test_the_mock_gateway_still_confirms_payments_in_development_and_testing(): void
    {
        [$token, $reservation] = $this->makeCustomerWithReservation();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", ['simulate' => 'sync_success']);

        $response->assertSuccessful();
        $this->assertEquals(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }
}
