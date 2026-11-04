<?php

namespace Tests\Feature\Admin;

use App\Models\Broadcast;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * When an action is refused (a delete blocked by a live booking, say) the controller sends
 * the admin back with a message in the session. A test that only checks the session proves
 * the controller did its part; this checks the admin can actually SEE the reason.
 *
 * It exists because the first version of that banner was built with a component that
 * silently rendered nothing, and every other test still passed.
 */
class ActionRefusalBannerTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_layout_shows_a_refusal_message_and_a_status_message(): void
    {
        $page = $this->actingAs($this->makeAdmin(), 'web')
            ->withSession(['error' => 'THIS-IS-THE-REFUSAL', 'status' => 'THIS-IS-THE-STATUS'])
            ->get(route('admin.dashboard'));

        $page->assertSee('THIS-IS-THE-REFUSAL')->assertSee('THIS-IS-THE-STATUS');
    }

    public function test_the_refusal_message_is_escaped(): void
    {
        $page = $this->actingAs($this->makeAdmin(), 'web')->withSession(['error' => '<script>alert(1)</script>'])->get(route('admin.dashboard'));

        $page->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_a_blocked_provider_delete_explains_itself_on_the_page_the_admin_lands_on(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $this->payFor($this->bookActivity($customer, $activity, $option));

        $this->actingAs($this->makeAdmin(), 'web')->from(route('admin.providers.show', $provider))->followingRedirects()
            ->delete(route('admin.providers.destroy', $provider))
            ->assertSee('1 upcoming booking');
    }

    public function test_a_blocked_customer_delete_explains_itself_on_the_page_the_admin_lands_on(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $this->payFor($this->bookActivity($customer, $activity, $option));

        $this->actingAs($this->makeAdmin(), 'web')->from(route('admin.customers.show', $customer))->followingRedirects()
            ->delete(route('admin.customers.destroy', $customer))
            ->assertSee('1 upcoming booking');
    }

    public function test_a_blocked_activity_delete_explains_itself_on_the_page_the_admin_lands_on(): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $this->payFor($this->bookActivity($customer, $activity, $option));

        $this->actingAs($this->makeAdmin(), 'web')->from(route('admin.activities.edit', $activity))->followingRedirects()
            ->delete(route('admin.activities.destroy', $activity))
            ->assertSee('1 upcoming booking');
    }

    public function test_a_blocked_announcement_send_explains_itself_on_the_page_the_admin_lands_on(): void
    {
        $broadcast = Broadcast::create(['title' => 'Nobody home', 'body' => 'x', 'audience' => 'providers', 'status' => 'draft', 'created_by' => $this->makeAdmin()->id]);

        $this->actingAs($this->makeAdmin(), 'web')->from(route('admin.notifications.broadcasts.show', $broadcast))->followingRedirects()
            ->post(route('admin.notifications.broadcasts.send', $broadcast))
            ->assertSee(__('admin.notifications.no_recipients'));
    }

    public function test_a_failed_announcement_shows_why_it_failed(): void
    {
        $broadcast = Broadcast::create(['title' => 'Broken', 'body' => 'x', 'audience' => 'customers', 'status' => 'failed', 'failed_reason' => 'REASON-FOR-FAILURE', 'created_by' => $this->makeAdmin()->id]);

        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.notifications.broadcasts.show', $broadcast))->assertSee('REASON-FOR-FAILURE');
    }
}
