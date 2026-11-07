<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Withdrawal;
use App\Services\Wallet\WithdrawalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** POST /provider/withdrawals, GET /provider/withdrawals and GET /provider/withdrawals/{id}. Today is Sunday 15 November 2026; the provider has 900 available. */
class ProviderWithdrawalApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($this->a, 1000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ask($amount = 500, string $key = 'wd-key-0001', ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'POST', '/api/v1/provider/withdrawals', ['amount' => $amount, 'idempotency_key' => $key]);
    }

    public function test_a_request_has_the_specs_fields_and_the_money_is_reserved_at_once(): void
    {
        $response = $this->ask()->assertCreated();

        $this->assertSame(['status' => true, 'message' => 'Withdrawal requested'], array_intersect_key($response->json(), ['status' => 1, 'message' => 1]));
        $data = $response->json('data');
        $this->assertSame(['id', 'amount', 'fee', 'net_amount', 'status', 'failure_reason', 'balance', 'created_at'], array_keys($data));
        $this->assertSame([500, 0, 500, 'pending', null], [$data['amount'], $data['fee'], $data['net_amount'], $data['status'], $data['failure_reason']]);
        $this->assertSame(['available' => 400, 'pending' => 0], $data['balance']);
        $this->assertSame('2026-11-15T12:00:00Z', $data['created_at']);

        $summary = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings')->json('data');
        $this->assertSame([400, 500], [$summary['available_balance'], $summary['reserved_balance']]);
    }

    public function test_it_cannot_be_more_than_what_is_available_nor_asked_for_twice(): void
    {
        $this->ask(900.01)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_BALANCE');
        $this->ask(500, 'wd-key-0001')->assertCreated();
        $this->ask(500, 'wd-key-0002')->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_BALANCE');

        $this->assertSame(1, Withdrawal::count());
    }

    public function test_the_same_idempotency_key_is_refused_the_second_time(): void
    {
        $this->ask(100, 'wd-same-key')->assertCreated();

        $this->ask(100, 'wd-same-key')->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_WITHDRAWAL');

        $this->assertSame(1, Withdrawal::count());
    }

    public function test_a_bank_account_is_needed_first(): void
    {
        $this->a['provider']->forceFill(['bank_account' => null])->save();

        $this->ask()->assertStatus(422)->assertJsonPath('code', 'BANK_ACCOUNT_REQUIRED');
    }

    public function test_the_amount_and_the_key_must_be_valid(): void
    {
        foreach ([[0, 'wd-key-0001'], [-5, 'wd-key-0001'], ['abc', 'wd-key-0001'], [null, 'wd-key-0001'], [100, 'short'], [100, 'has spaces in it'], [100, '']] as [$amount, $key]) {
            $this->ask($amount, $key)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, Withdrawal::count());
    }

    public function test_the_smallest_withdrawal_comes_from_the_configuration(): void
    {
        config(['wallet.min_withdrawal' => 100]);

        $this->ask(50)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->ask(100, 'wd-key-0002')->assertCreated();
    }

    public function test_a_withdrawal_fee_comes_from_the_configuration(): void
    {
        config(['wallet.withdrawal_fee_percent' => 5]);

        $data = $this->ask(400)->assertCreated()->json('data');

        $this->assertSame([400, 20, 380], [$data['amount'], $data['fee'], $data['net_amount']]);
    }

    public function test_money_in_the_waiting_period_cannot_be_withdrawn(): void
    {
        config(['wallet.settlement_delay_days' => 3]);
        $b = $this->approvedProvider(['commission_rate' => 0]);
        $b['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($b, 600, '2026-11-14');

        $this->ask(100, 'wd-key-0001', $b)->assertStatus(422)->assertJsonPath('code', 'INSUFFICIENT_AVAILABLE_BALANCE');
        $this->assertSame(600, $this->hit($b['token'], 'GET', '/api/v1/provider/earnings')->json('data.pending_balance'));
    }

    public function test_the_list_shows_my_requests_newest_first_with_the_specs_paging(): void
    {
        foreach (range(1, 3) as $n) {
            $this->ask(100, "wd-key-000{$n}")->assertCreated();
        }
        $mine = Withdrawal::orderByDesc('id')->get();
        $mine[0]->update(['status' => 'paid']);

        $all = $this->hit($this->a['token'], 'GET', '/api/v1/provider/withdrawals?per_page=2&page=1')->assertOk();
        $this->assertSame($mine->pluck('id')->take(2)->all(), array_column($all->json('data'), 'id'));
        $this->assertSame(['id', 'amount', 'fee', 'net_amount', 'status', 'failure_reason'], array_keys($all->json('data.0')));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $all->json('meta'));

        $paid = $this->hit($this->a['token'], 'GET', '/api/v1/provider/withdrawals?status=paid')->json('data');
        $this->assertSame([$mine[0]->id], array_column($paid, 'id'));
        $this->hit($this->a['token'], 'GET', '/api/v1/provider/withdrawals?status=nonsense')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_a_provider_only_sees_their_own_withdrawals(): void
    {
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $other['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($other, 300);
        $theirs = $this->ask(100, 'other-key-1', $other)->assertCreated()->json('data.id');
        $this->ask(100, 'wd-key-0001')->assertCreated();

        $list = $this->hit($this->a['token'], 'GET', '/api/v1/provider/withdrawals')->json('data');
        $this->assertNotContains($theirs, array_column($list, 'id'));
        $this->assertCount(1, $list);
    }

    public function test_the_details_of_one_withdrawal(): void
    {
        $id = $this->ask()->json('data.id');
        Withdrawal::find($id)->update(['status' => 'paid', 'payout_reference' => 'TRF-9', 'processed_at' => now()]);

        $data = $this->hit($this->a['token'], 'GET', "/api/v1/provider/withdrawals/{$id}")->assertOk()->json('data');

        $this->assertSame(['id', 'amount', 'fee', 'net_amount', 'status', 'failure_reason', 'payout_reference', 'created_at', 'processed_at'], array_keys($data));
        $this->assertSame(['paid', 'TRF-9', null], [$data['status'], $data['payout_reference'], $data['failure_reason']]);
    }

    public function test_a_rejected_withdrawal_shows_why(): void
    {
        $id = $this->ask()->json('data.id');
        Withdrawal::find($id)->update(['status' => 'rejected', 'status_reason' => 'The account name does not match']);

        $this->assertSame('The account name does not match', $this->hit($this->a['token'], 'GET', "/api/v1/provider/withdrawals/{$id}")->json('data.failure_reason'));
    }

    public function test_another_providers_withdrawal_is_not_found_like_one_that_does_not_exist(): void
    {
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $other['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($other, 300);
        $theirs = $this->ask(100, 'other-key-1', $other)->json('data.id');

        $foreign = $this->hit($this->a['token'], 'GET', "/api/v1/provider/withdrawals/{$theirs}")->assertNotFound();
        $missing = $this->hit($this->a['token'], 'GET', '/api/v1/provider/withdrawals/999999')->assertNotFound();
        $text = $this->hit($this->a['token'], 'GET', '/api/v1/provider/withdrawals/abc')->assertNotFound();

        foreach ([$foreign, $missing, $text] as $r) {
            $this->assertSame('RESOURCE_NOT_FOUND', $r->json('code'));
        }
        $this->assertSame($foreign->json('message'), $missing->json('message'), 'It must not reveal which ids exist');
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->ask(100, 'wd-key-0001', $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'POST', '/api/v1/provider/withdrawals', ['amount' => 100, 'idempotency_key' => 'wd-key-0001'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'GET', '/api/v1/provider/withdrawals')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->assertSame(0, Withdrawal::count());
    }

    public function test_the_admin_paying_it_is_visible_to_the_provider(): void
    {
        $id = $this->ask()->json('data.id');
        $this->seed(RolesAndPermissionsSeeder::class);
        app(WithdrawalService::class)->markPaid(Withdrawal::find($id), $this->makeAdmin(), 'TRF-55');

        $this->assertSame('paid', $this->hit($this->a['token'], 'GET', "/api/v1/provider/withdrawals/{$id}")->json('data.status'));
        $summary = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings')->json('data');
        $this->assertSame([0, 500, 400], [$summary['reserved_balance'], $summary['total_withdrawn'], $summary['available_balance']]);
        $this->assertSame(500, $summary['last_payout']['amount']);
    }
}
