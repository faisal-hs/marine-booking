<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Withdrawal;
use App\Services\Wallet\WithdrawalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/earnings, /earnings/statement and /earnings/report. Today is Sunday 15 November 2026, the provider's commission is 10%, and:
 *
 *   Reef Dive      1000, 100 refunded   gross 1000, net 1000 - 100 - 90 = 810
 *   Sunset Cruise   500                 gross  500, net  500 -  50 = 450
 *   then 200 withdrawn and paid         available 1260 - 200 = 1060
 */
class ProviderEarningsApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Both bookings, and 200 paid out. */
    private function earn(): void
    {
        [$cruise] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $this->fundWallet($this->a, 1000, '2026-11-10', 100);
        $second = $this->a;
        $second['activity'] = $cruise;
        $second['option'] = $cruise->bookingOptions()->first();
        $second['period'] = $cruise->schedulePeriods()->value('id');
        $this->fundWallet($second, 500, '2026-11-11');

        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $w = app(WithdrawalService::class)->request($this->a['provider']->refresh(), 200, 'wd-key-0001');
        app(WithdrawalService::class)->markPaid($w, $this->makeAdmin(), 'TRF-1');
    }

    private function summary(?array $as = null): array
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/earnings')->assertOk()->json('data');
    }

    // --- The summary ----------------------------------------------------------------------------------------------------

    public function test_the_summary_has_the_specs_fields_and_the_ledgers_figures(): void
    {
        $this->earn();

        $d = $this->summary();

        $this->assertSame(['currency', 'available_balance', 'pending_balance', 'reserved_balance', 'total_earnings', 'total_withdrawn', 'gross_revenue', 'net_revenue',
            'last_payout', 'revenue_breakdown', 'transactions'], array_keys($d));
        $this->assertSame('SAR', $d['currency']);
        $this->assertSame([1500, 1260, 1060, 0, 0, 1260, 200], [$d['gross_revenue'], $d['net_revenue'], $d['available_balance'], $d['pending_balance'], $d['reserved_balance'], $d['total_earnings'], $d['total_withdrawn']]);
        $this->assertSame(['date' => '2026-11-15T12:00:00Z', 'amount' => 200], $d['last_payout']);
    }

    public function test_the_breakdown_is_by_activity_best_first(): void
    {
        $this->earn();

        $this->assertSame([['activity' => 'Reef Dive', 'gross' => 1000, 'net' => 810], ['activity' => 'Sunset Cruise', 'gross' => 500, 'net' => 450]], $this->summary()['revenue_breakdown']);
    }

    public function test_the_transactions_are_the_ledger_in_the_specs_words(): void
    {
        $this->earn();

        $transactions = collect($this->summary()['transactions']);

        $this->assertSame(['income', 'fee', 'refund', 'payout'], $transactions->pluck('type')->unique()->values()->sortBy(fn ($t) => array_search($t, ['income', 'fee', 'refund', 'payout']))->values()->all());
        $this->assertSame(['id', 'title', 'amount', 'type'], array_keys($transactions[0]));
        $this->assertSame(-200, $transactions->firstWhere('type', 'payout')['amount']);
        $this->assertSame(-90, $transactions->first(fn ($t) => str_starts_with($t['title'], 'Platform fee - Booking') && $t['amount'] === -90)['amount']);
        $this->assertTrue($transactions->contains(fn ($t) => $t['type'] === 'income' && $t['amount'] === 1000 && str_contains($t['title'], 'Reef Dive')));
    }

    public function test_a_provider_with_nothing_yet_sees_zeros(): void
    {
        $d = $this->summary();

        $this->assertSame([0, 0, 0, 0, 0, 0, 0], [$d['available_balance'], $d['pending_balance'], $d['reserved_balance'], $d['gross_revenue'], $d['net_revenue'], $d['total_earnings'], $d['total_withdrawn']]);
        $this->assertNull($d['last_payout']);
        $this->assertSame([[], []], [$d['revenue_breakdown'], $d['transactions']]);
    }

    public function test_it_shows_only_my_own_money(): void
    {
        $this->earn();
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $this->fundWallet($other, 777);

        $this->assertSame(1500, $this->summary()['gross_revenue']);
        $this->assertSame(777, $this->summary($other)['gross_revenue']);
    }

    // --- The statement ------------------------------------------------------------------------------------------------

    public function test_the_statement_is_an_expiring_signed_link_to_a_csv_of_that_month(): void
    {
        $this->earn();
        $this->fundWallet($this->a, 300, '2026-10-30');   // another month

        $response = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/statement?month=2026-11')->assertOk();
        $data = $response->json('data');
        $this->assertSame(['url', 'month', 'currency'], array_keys($data));
        $this->assertSame(['2026-11', 'SAR'], [$data['month'], $data['currency']]);
        $this->assertStringContainsString('signature=', $data['url']);
        $this->assertStringContainsString('expires=', $data['url']);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $file = $this->get($data['url'])->assertOk();   // no token: the signature is what proves it
        $csv = $file->streamedContent();

        $this->assertStringContainsString('attachment; filename=statement-2026-11.csv', $file->headers->get('content-disposition'));
        $this->assertStringContainsString('Date,Description,Type,"Amount (SAR)"', $csv);
        $this->assertStringContainsString('2026-11-10,"Booking #', $csv);
        $this->assertStringContainsString('Reef Dive",income,1000.00', $csv);
        $this->assertStringContainsString('Withdrawal request', $csv);
        $this->assertStringNotContainsString('2026-10-30', $csv, 'Another month is not in it');
        $this->assertStringContainsString(',Total,,1060.00', $csv, '1260 earned less the 200 withdrawn');
    }

    public function test_a_statement_link_cannot_be_altered_and_stops_working(): void
    {
        $this->earn();
        $url = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/statement?month=2026-11')->json('data.url');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->get(str_replace('month=2026-11', 'month=2026-10', $url))->assertForbidden();
        $this->get(preg_replace('/provider=[^&]+/', 'provider='.Str::uuid(), $url))->assertForbidden();
        $this->getJson('/api/v1/provider/earnings/statement/download?month=2026-11&provider='.$this->a['provider']->uuid)->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(16));
        $this->get($url)->assertForbidden();
    }

    public function test_the_statement_belongs_to_the_provider_the_link_was_made_for(): void
    {
        $this->earn();
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $this->fundWallet($other, 321, '2026-11-12');
        $url = $this->hit($other['token'], 'GET', '/api/v1/provider/earnings/statement?month=2026-11')->json('data.url');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $csv = $this->get($url)->streamedContent();

        $this->assertStringContainsString('321.00', $csv);
        // The first provider's bookings are #1 and #2, the other's is #3 (every demo provider has a "Reef Dive", so the title proves nothing).
        $this->assertStringContainsString('Booking #3 -', $csv);
        $this->assertStringNotContainsString('Booking #1 -', $csv);
        $this->assertStringNotContainsString('Booking #2 -', $csv);
        $this->assertStringNotContainsString('1000.00', $csv);
    }

    public function test_the_statement_needs_a_valid_month(): void
    {
        foreach (['', '2026-13', '11-2026', 'november'] as $bad) {
            $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/statement?month='.$bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_the_link_lifetime_comes_from_the_configuration(): void
    {
        config(['wallet.statement_link_minutes' => 1]);
        $url = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/statement?month=2026-11')->json('data.url');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->get($url)->assertForbidden();
    }

    // --- The annual report ---------------------------------------------------------------------------------------------

    public function test_the_annual_report(): void
    {
        $this->earn();
        $this->fundWallet($this->a, 400, '2026-03-05');

        $r = $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/report?year=2026')->assertOk()->json('data');

        $this->assertSame(['year', 'currency', 'gross_total', 'net_total', 'change_percent', 'monthly', 'pending_payout', 'completed_trips', 'avg_per_booking'], array_keys($r));
        $this->assertSame([2026, 'SAR', 1900, 1620, 3, 633.33], [$r['year'], $r['currency'], $r['gross_total'], $r['net_total'], $r['completed_trips'], $r['avg_per_booking']]);
        $this->assertCount(12, $r['monthly']);
        $this->assertSame(['month' => 3, 'gross' => 400, 'net' => 360], $r['monthly'][2]);
        $this->assertSame(['month' => 11, 'gross' => 1500, 'net' => 1260], $r['monthly'][10]);
        $this->assertSame(['month' => 1, 'gross' => 0, 'net' => 0], $r['monthly'][0]);
        $this->assertNull($r['change_percent'], 'There is no earlier year to compare with');
    }

    public function test_the_report_compares_with_the_year_before(): void
    {
        $this->fundWallet($this->a, 1000, '2025-06-01');   // net 900
        $this->fundWallet($this->a, 2000, '2026-06-01');   // net 1800

        $this->assertSame(100, $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/report?year=2026')->json('data.change_percent'));
    }

    public function test_the_reports_pending_payout_is_what_is_held_for_withdrawals(): void
    {
        $this->fundWallet($this->a, 1000);
        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        app(WithdrawalService::class)->request($this->a['provider']->refresh(), 300, 'wd-key-0001');

        $this->assertSame(300, $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/report?year=2026')->json('data.pending_payout'));
    }

    public function test_the_report_needs_a_valid_year(): void
    {
        foreach (['', 'abc', '1800', '3000'] as $bad) {
            $this->hit($this->a['token'], 'GET', '/api/v1/provider/earnings/report?year='.$bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        foreach (['/earnings', '/earnings/statement?month=2026-11', '/earnings/report?year=2026'] as $uri) {
            $this->hit($this->unapprovedProvider()['token'], 'GET', '/api/v1/provider'.$uri)->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
            [$customer] = $this->makeCustomer();
            $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider'.$uri)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
            $this->hit(null, 'GET', '/api/v1/provider'.$uri)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        }
        $this->assertSame(0, Withdrawal::count());
    }
}
