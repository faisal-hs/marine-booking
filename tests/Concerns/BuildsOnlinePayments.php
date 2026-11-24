<?php

namespace Tests\Concerns;

use App\Models\Payment;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * What the Tabby and Tamara tests share: a provider whose activity accepts both, a signed-in customer, the gateways switched on with test credentials, and booking through the real
 * endpoint (which is where a payment starts). Today is Sunday 15 November 2026, 12:00, so a booking's hold runs to 12:15.
 */
trait BuildsOnlinePayments
{
    use BuildsCustomerAppScenarios;

    protected array $a;

    protected string $token;

    protected function setUpOnline(): void
    {
        $this->pinTime();
        $this->a = $this->approvedProvider();
        $this->a['activity']->update(['allows_tabby' => true, 'allows_tamara' => true]);
        [, , $this->token] = $this->signedInCustomer();
    }

    protected function tearDownOnline(): void
    {
        Carbon::setTestNow();
    }

    protected function enableTabby(array $over = []): void
    {
        config(['payments.gateways.tabby' => $over + ['enabled' => true, 'environment' => 'sandbox', 'base_url' => 'https://api.tabby.test', 'public_key' => 'pk_test_public', 'secret_key' => 'sk_test_secret',
            'merchant_code' => 'SA', 'webhook_header' => 'X-Tabby-Webhook-Secret', 'webhook_secret' => 'tabby-hook-secret', 'timeout' => 5, 'currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'QAR']]]);
    }

    protected function enableTamara(array $over = []): void
    {
        config(['payments.gateways.tamara' => $over + ['enabled' => true, 'environment' => 'sandbox', 'base_url' => 'https://api.tamara.test', 'api_token' => 'tamara-api-token', 'notification_token' => 'tamara-notification-secret',
            'country_code' => 'SA', 'timeout' => 5, 'currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'QAR', 'OMR']]]);
    }

    /** A booking of 2 guests (3,000 SAR in the scenario) paid with `$method`; the payment starts with it. */
    protected function bookWith(string $method, int $guests = 2, array $over = [], ?string $key = null)
    {
        return $this->ca($this->token, 'POST', '/bookings', $over + ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => $guests,
            'contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '0501234567', 'contact_email' => 'ahmed@example.test', 'payment_method' => $method], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function payment(): Payment
    {
        return Payment::query()->latest('id')->firstOrFail();
    }

    protected function reservation(): Reservation
    {
        return Reservation::query()->latest('id')->firstOrFail();
    }

    /** Pay again for a booking that is still waiting (the retry path). */
    protected function payAgain(Reservation $reservation, ?string $method = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->postJson("/api/v1/customer/reservations/{$reservation->uuid}/payments", array_filter(['payment_method' => $method]));
    }
}
