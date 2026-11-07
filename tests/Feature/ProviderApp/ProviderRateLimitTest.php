<?php

namespace Tests\Feature\ProviderApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The provider app's write endpoints are rate limited, each with a budget of its own. (With Laravel's numeric throttle:10,1 every such route shared ONE
 * counter per user, and a provider doing ordinary things could be locked out of withdrawing.)
 */
class ProviderRateLimitTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->approvedProvider();
    }

    private function withdraw(?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'POST', '/api/v1/provider/withdrawals', ['amount' => -1, 'idempotency_key' => 'short']);   // refused, but it counts
    }

    private function bank(?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'PUT', '/api/v1/provider/bank-account', ['iban' => 'nope']);
    }

    public function test_withdrawal_requests_are_limited_to_ten_a_minute_and_the_refusal_is_in_the_specs_shape(): void
    {
        foreach (range(1, 10) as $n) {
            $this->withdraw()->assertStatus(422);
        }

        $this->withdraw()->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS')->assertJsonPath('status', false);
    }

    public function test_other_endpoints_do_not_use_up_the_withdrawal_budget(): void
    {
        foreach (range(1, 15) as $n) {
            $this->bank()->assertStatus(422);   // its own budget is 20
        }
        $this->hit($this->a['token'], 'POST', '/api/v1/provider/bookings/999999/cancel', ['reason' => 'No such booking'])->assertNotFound();

        foreach (range(1, 10) as $n) {
            $this->withdraw()->assertStatus(422);   // all ten are still available
        }
        $this->withdraw()->assertStatus(429);
    }

    public function test_withdrawals_do_not_use_up_the_other_budgets(): void
    {
        foreach (range(1, 11) as $n) {
            $this->withdraw();
        }

        $this->bank()->assertStatus(422);
        $this->hit($this->a['token'], 'POST', '/api/v1/provider/bookings/999999/cancel', ['reason' => 'No such booking'])->assertNotFound();
    }

    public function test_each_provider_has_their_own_budget(): void
    {
        $other = $this->approvedProvider();
        foreach (range(1, 11) as $n) {
            $this->withdraw();
        }
        $this->withdraw()->assertStatus(429);

        $this->withdraw($other)->assertStatus(422);
    }

    public function test_the_bank_account_budget_is_twenty_a_minute(): void
    {
        foreach (range(1, 20) as $n) {
            $this->bank()->assertStatus(422);
        }

        $this->bank()->assertStatus(429);
    }
}
