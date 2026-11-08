<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ActivityView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * A page view is one VISITOR looking at one live activity on one DAY. A refresh adds nothing, the provider's own looks and robots do not count, the
 * visitor is stored as a hash nobody can turn back into a person, and `source` says how they arrived.
 */
class ActivityViewTrackingTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function look(?string $path = null, array $server = [], array $headers = [])
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables($server + ['REMOTE_ADDR' => '203.0.113.7'])->withHeaders($headers + ['User-Agent' => 'Mozilla/5.0 (a real browser)'])
            ->getJson($path ?? "/api/v1/public/activities/{$this->a['activity']->slug}");
    }

    public function test_looking_at_a_live_activity_is_a_view(): void
    {
        $this->look()->assertOk();

        $view = ActivityView::firstOrFail();
        $this->assertSame([$this->a['activity']->id, '2026-11-15', 'direct'], [$view->activity_id, $view->viewed_on->toDateString(), $view->source]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $view->visitor_hash);
    }

    public function test_the_same_visitor_on_the_same_day_is_one_view_however_often_they_refresh(): void
    {
        foreach (range(1, 5) as $n) {
            $this->look()->assertOk();
        }

        $this->assertSame(1, ActivityView::count());
    }

    public function test_the_same_visitor_the_next_day_is_another_view(): void
    {
        $this->look();
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->look();

        $this->assertSame(['2026-11-15', '2026-11-16'], ActivityView::orderBy('id')->get()->map(fn ($v) => $v->viewed_on->toDateString())->all());
    }

    public function test_different_visitors_are_different_views(): void
    {
        $this->look(null, ['REMOTE_ADDR' => '203.0.113.7']);
        $this->look(null, ['REMOTE_ADDR' => '203.0.113.8']);
        $this->look(null, ['REMOTE_ADDR' => '203.0.113.7'], ['User-Agent' => 'Mozilla/5.0 (another browser)']);

        $this->assertSame(3, ActivityView::count());
        $this->assertSame(3, ActivityView::distinct()->count('visitor_hash'));
    }

    public function test_a_signed_in_customer_is_one_visitor_wherever_they_come_from(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');

        foreach (['203.0.113.7', '198.51.100.9'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['Authorization' => "Bearer {$token}", 'User-Agent' => 'Mozilla/5.0'])->getJson("/api/v1/public/activities/{$this->a['activity']->slug}")->assertOk();
        }

        $this->assertSame(1, ActivityView::count());
    }

    public function test_the_visitor_cannot_be_read_back_from_what_is_stored(): void
    {
        $this->look();

        $hash = ActivityView::firstOrFail()->visitor_hash;

        $this->assertNotSame(sha1('203.0.113.7'), $hash);
        $this->assertNotSame(sha1('203.0.113.7|Mozilla/5.0 (a real browser)'), $hash, 'It is salted with the application key');
        $this->assertStringNotContainsString('203.0.113.7', json_encode(ActivityView::firstOrFail()->toArray()));
    }

    public function test_the_providers_own_looks_do_not_count(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$this->a['token']}", 'User-Agent' => 'Mozilla/5.0'])->getJson("/api/v1/public/activities/{$this->a['activity']->slug}")->assertOk();

        $this->assertSame(0, ActivityView::count());
    }

    public function test_robots_do_not_count(): void
    {
        foreach (['Googlebot/2.1', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'Slackbot-LinkExpanding', 'SomeCrawler/1.0', 'UptimeMonitor'] as $robot) {
            $this->look(null, [], ['User-Agent' => $robot])->assertOk();
        }

        $this->assertSame(0, ActivityView::count());
    }

    public function test_an_activity_that_is_not_live_or_does_not_exist_records_nothing(): void
    {
        $this->a['activity']->update(['status' => 'inactive']);

        $this->look()->assertNotFound();
        $this->look('/api/v1/public/activities/no-such-activity')->assertNotFound();

        $this->assertSame(0, ActivityView::count());
    }

    // --- How they arrived ---------------------------------------------------------------------------------------------

    public function test_the_source_comes_from_the_link_or_a_header_and_anything_else_is_direct(): void
    {
        $slug = $this->a['activity']->slug;
        $cases = [['?source=social', [], 'social', '203.0.113.1'], ['?source=Category', [], 'category', '203.0.113.2'], ['?source=recommendations', [], 'recommendations', '203.0.113.3'],
            ['', ['X-Booking-Source' => 'social'], 'social', '203.0.113.4'], ['?source=newsletter', [], 'direct', '203.0.113.5'], ['', [], 'direct', '203.0.113.6']];

        foreach ($cases as [$query, $headers, $expected, $ip]) {
            $this->look("/api/v1/public/activities/{$slug}{$query}", ['REMOTE_ADDR' => $ip], $headers)->assertOk();
            $this->assertSame($expected, ActivityView::orderByDesc('id')->first()->source, "{$query}".json_encode($headers));
        }
    }

    public function test_the_first_source_of_the_day_stays(): void
    {
        $slug = $this->a['activity']->slug;

        $this->look("/api/v1/public/activities/{$slug}?source=social");
        $this->look("/api/v1/public/activities/{$slug}?source=category");

        $this->assertSame(['social'], ActivityView::pluck('source')->all());
    }

    // --- The public site counts too -------------------------------------------------------------------------------------

    public function test_a_look_at_the_public_page_is_a_view_as_well(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get("/activities/{$this->a['activity']->slug}")->assertOk();

        $this->assertSame([$this->a['activity']->id], ActivityView::pluck('activity_id')->all());
    }

    public function test_a_view_goes_when_its_activity_goes(): void
    {
        $this->look();

        $this->a['activity']->forceDelete();

        $this->assertSame(0, ActivityView::count());
    }
}
