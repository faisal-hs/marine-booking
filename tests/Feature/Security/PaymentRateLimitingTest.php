<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Starting a payment is what costs money once a real gateway replaces the mock, so it is limited to ten attempts a
 * minute per customer. The limit sits in front of the controller: it counts attempts however they end.
 */
class PaymentRateLimitingTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private function startPayment(string $token, string $reservation = '00000000-0000-4000-8000-000000000000')
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson("/api/v1/customer/reservations/{$reservation}/payments", ['simulate' => 'sync_success']);
    }

    public function test_the_eleventh_payment_attempt_in_a_minute_is_refused(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');

        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotSame(429, $this->startPayment($token)->status(), "Attempt {$i} was limited too early");
        }

        $this->startPayment($token)->assertStatus(429);
    }

    public function test_the_limit_belongs_to_the_customer_not_to_the_place_they_are_calling_from(): void
    {
        [$first] = $this->makeCustomer();
        [$second] = $this->makeCustomer();
        $firstToken = $this->tokenFor($first, 'customer');

        for ($i = 0; $i < 11; $i++) {
            $this->startPayment($firstToken);
        }
        $this->startPayment($firstToken)->assertStatus(429);

        // Same address, a different customer: not affected.
        $this->assertNotSame(429, $this->startPayment($this->tokenFor($second, 'customer'))->status());
    }

    public function test_the_limit_lifts_after_a_minute(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');

        for ($i = 0; $i < 10; $i++) {
            $this->startPayment($token);
        }
        $this->startPayment($token)->assertStatus(429);

        $this->travel(61)->seconds();

        $this->assertNotSame(429, $this->startPayment($token)->status());
    }

    public function test_reading_a_payment_is_not_counted_against_the_limit(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');

        for ($i = 0; $i < 15; $i++) {
            $this->app['auth']->forgetGuards();
            $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
                ->getJson('/api/v1/customer/payments/00000000-0000-4000-8000-000000000000');
        }

        $this->assertNotSame(429, $this->startPayment($token)->status(), 'Reading payments must not use up the attempts to start one');
    }
}
