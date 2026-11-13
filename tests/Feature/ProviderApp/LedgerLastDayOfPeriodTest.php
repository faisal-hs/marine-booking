<?php

namespace Tests\Feature\ProviderApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * A trip on the LAST day of a period counts in that period. The ledger keeps a day as 2026-11-30 00:00:00 on SQLite, so a range compared as text and
 * ending on 2026-11-30 used to leave that day out: the last day of a month, a statement, a year, or an analytics period.
 */
class LedgerLastDayOfPeriodTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fetch(string $path): array
    {
        return $this->hit($this->a['token'], 'GET', "/api/v1/provider{$path}")->assertOk()->json('data');
    }

    public function test_the_dashboard_counts_a_trip_on_the_last_day_of_the_month_and_of_a_week(): void
    {
        $this->fundWallet($this->a, 700, '2026-11-30');
        $this->fundWallet($this->a, 300, '2026-11-01');
        $this->fundWallet($this->a, 200, '2026-11-21');   // the last day (a Saturday) of the current week

        $d = $this->fetch('/dashboard');

        $this->assertEquals(1200, $d['month_revenue']['gross']);
        $this->assertEquals([0, 300, 0, 200], $d['weekly_revenue'], 'The 1st is in its week, and the last day of the current week counts in it');
    }

    public function test_the_first_and_last_day_of_a_week_both_belong_to_it(): void
    {
        $this->fundWallet($this->a, 100, '2026-11-08');   // the first day of last week
        $this->fundWallet($this->a, 400, '2026-11-14');   // its last day

        $this->assertEquals([0, 0, 500, 0], $this->fetch('/dashboard')['weekly_revenue']);
    }

    public function test_the_yearly_report_counts_the_thirty_first_of_december(): void
    {
        $this->fundWallet($this->a, 1000, '2026-12-31');
        $this->fundWallet($this->a, 500, '2026-01-01');

        $this->assertEquals(1500, $this->fetch('/earnings/report?year=2026')['gross_total']);
    }

    public function test_a_monthly_statement_lists_a_trip_on_the_last_day_of_the_month(): void
    {
        $this->fundWallet($this->a, 700, '2026-11-30');
        $url = $this->fetch('/earnings/statement?month=2026-11')['url'];

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $csv = $this->getJson($url)->assertOk()->streamedContent();

        $this->assertStringContainsString('2026-11-30', $csv);
        $this->assertStringContainsString('700.00', $csv);
    }

    public function test_analytics_for_a_period_ending_on_a_trip_day_includes_it(): void
    {
        $this->fundWallet($this->a, 700, '2026-11-30');

        $top = $this->fetch('/analytics?from=2026-11-01&to=2026-11-30')['top_activities'];

        $this->assertEquals([700], array_column($top, 'gross_revenue'));
    }
}
