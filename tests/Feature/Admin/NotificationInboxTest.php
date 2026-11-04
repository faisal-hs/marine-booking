<?php

namespace Tests\Feature\Admin;

use App\Services\NotificationLinkResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The signed-in admin's own notifications. Every action works on THEIR entries only: someone
 * else's entry cannot be read, changed or deleted by guessing its id.
 */
class NotificationInboxTest extends TestCase
{
    use AssertsTranslated;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Who may use it ----------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> [role, may open the inbox] */
    public static function roles(): array
    {
        return [
            'super admin' => ['super_admin', true],
            'admin' => ['admin', true],
            'support admin' => ['support_admin', true],
            'content admin (no notification permission)' => ['content_admin', false],
        ];
    }

    #[DataProvider('roles')]
    public function test_the_inbox_needs_the_view_permission(string $role, bool $allowed): void
    {
        $admin = $this->makeAdmin($role);

        $response = $this->actingAs($admin, 'web')->get(route('admin.notifications.index'));

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.notifications.index'))->assertRedirect(route('admin.login'));
    }

    // --- What it shows -----------------------------------------------------------

    public function test_it_shows_my_notifications_and_nobody_elses(): void
    {
        $me = $this->makeAdmin();
        $other = $this->makeAdmin('admin');
        $this->giveNotification($me, ['message' => 'For me']);
        $this->giveNotification($other, ['message' => 'For somebody else']);

        $this->actingAs($me, 'web')->get(route('admin.notifications.index'))
            ->assertOk()->assertSee('For me')->assertDontSee('For somebody else');
    }

    public function test_the_unread_filter_and_the_count(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me, ['message' => 'Not yet read']);
        $this->giveNotification($me, ['message' => 'Already read'], read: true);
        $this->giveNotification($me, ['message' => 'Also unread']);

        $all = $this->actingAs($me, 'web')->get(route('admin.notifications.index'));
        $all->assertSee('Not yet read')->assertSee('Already read')->assertSee(__('admin.notifications.unread_count', ['count' => 2]));

        $this->actingAs($me, 'web')->get(route('admin.notifications.index', ['status' => 'unread']))
            ->assertSee('Not yet read')->assertSee('Also unread')->assertDontSee('Already read');
    }

    public function test_a_title_is_shown_and_a_missing_message_falls_back_to_the_type(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me, ['title' => 'Big News', 'message' => 'Details here']);
        $this->giveNotification($me, ['x' => 1], 'App\\Notifications\\Payments\\Admin\\PaymentFailedAdminNotification');

        $this->actingAs($me, 'web')->get(route('admin.notifications.index'))
            ->assertSee('Big News')->assertSee('Details here')->assertSee('Payment Failed Admin Notification');
    }

    public function test_message_text_is_escaped(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me, ['message' => '<script>alert("x")</script>']);

        $this->actingAs($me, 'web')->get(route('admin.notifications.index'))
            ->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.notifications.index'))->assertSee(__('admin.notifications.empty'));
    }

    // --- Actions -------------------------------------------------------------------

    public function test_a_notification_can_be_marked_read_and_unread(): void
    {
        $me = $this->makeAdmin();
        $notification = $this->giveNotification($me);

        $this->actingAs($me, 'web')->post(route('admin.notifications.read', $notification->id))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($me, 'web')->post(route('admin.notifications.unread', $notification->id))->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_all_can_be_marked_read_at_once_without_touching_anybody_elses(): void
    {
        $me = $this->makeAdmin();
        $other = $this->makeAdmin('admin');
        $mine = [$this->giveNotification($me), $this->giveNotification($me)];
        $theirs = $this->giveNotification($other);

        $this->actingAs($me, 'web')->post(route('admin.notifications.read-all'))->assertSessionHas('status');

        foreach ($mine as $n) {
            $this->assertNotNull($n->fresh()->read_at);
        }
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_notification_can_be_deleted(): void
    {
        $me = $this->makeAdmin();
        $notification = $this->giveNotification($me);

        $this->actingAs($me, 'web')->delete(route('admin.notifications.destroy', $notification->id))->assertSessionHas('status');

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_someone_elses_notification_cannot_be_read_changed_or_deleted(): void
    {
        $me = $this->makeAdmin();
        $victim = $this->makeAdmin('admin');
        $theirs = $this->giveNotification($victim, ['message' => 'Private']);

        $this->actingAs($me, 'web')->post(route('admin.notifications.read', $theirs->id))->assertNotFound();
        $this->actingAs($me, 'web')->post(route('admin.notifications.unread', $theirs->id))->assertNotFound();
        $this->actingAs($me, 'web')->delete(route('admin.notifications.destroy', $theirs->id))->assertNotFound();

        $this->assertNull($theirs->fresh()->read_at);
        $this->assertDatabaseHas('notifications', ['id' => $theirs->id]);
    }

    public function test_a_customers_notification_cannot_be_deleted_through_the_inbox_either(): void
    {
        [$customerUser] = $this->makeCustomer();
        $entry = $this->giveNotification($customerUser);

        $this->actingAs($this->makeAdmin(), 'web')->delete(route('admin.notifications.destroy', $entry->id))->assertNotFound();

        $this->assertDatabaseHas('notifications', ['id' => $entry->id]);
    }

    public function test_a_made_up_id_is_not_found_and_not_a_server_error(): void
    {
        $me = $this->makeAdmin();

        $this->actingAs($me, 'web')->post(route('admin.notifications.read', 'not-a-real-id'))->assertNotFound();
        $this->actingAs($me, 'web')->delete(route('admin.notifications.destroy', '00000000-0000-0000-0000-000000000000'))->assertNotFound();
    }

    public function test_the_actions_need_the_view_permission(): void
    {
        $content = $this->makeAdmin('content_admin');
        $entry = $this->giveNotification($content);

        $this->actingAs($content, 'web')->post(route('admin.notifications.read', $entry->id))->assertForbidden();
        $this->actingAs($content, 'web')->post(route('admin.notifications.read-all'))->assertForbidden();
        $this->actingAs($content, 'web')->delete(route('admin.notifications.destroy', $entry->id))->assertForbidden();
        $this->assertDatabaseHas('notifications', ['id' => $entry->id]);
    }

    // --- Links to what a notification is about -------------------------------------------

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function targets(): array
    {
        return [
            'a reservation' => [['reservation_uuid' => 'res-uuid-1'], 'admin/reservations/res-uuid-1'],
            'a payment' => [['payment_uuid' => 'pay-uuid-1'], 'admin/payments/pay-uuid-1'],
            'a provider' => [['provider_uuid' => 'prov-uuid-1'], 'admin/providers/prov-uuid-1'],
            'an activity' => [['activity_uuid' => 'act-uuid-1'], 'admin/activities/act-uuid-1'],
        ];
    }

    #[DataProvider('targets')]
    public function test_a_notification_links_to_the_thing_it_is_about(array $data, string $expectedPath): void
    {
        $url = app(NotificationLinkResolver::class)->urlFor($data, $this->makeAdmin('super_admin'));

        $this->assertNotNull($url);
        $this->assertStringEndsWith($expectedPath, $url);
    }

    public function test_no_link_is_offered_for_a_page_the_admin_may_not_open(): void
    {
        $viewOnlyNotifications = $this->makeAdmin('super_admin');
        $viewOnlyNotifications->syncRoles([]);
        $viewOnlyNotifications->givePermissionTo('notifications.view');

        $resolver = app(NotificationLinkResolver::class);

        $this->assertNull($resolver->urlFor(['reservation_uuid' => 'x'], $viewOnlyNotifications));
        $this->assertNull($resolver->urlFor(['payment_uuid' => 'x', 'provider_uuid' => 'y'], $viewOnlyNotifications));
        $this->assertNull($resolver->urlFor(['message' => 'nothing to link to'], $this->makeAdmin('super_admin')));
    }

    public function test_the_open_button_appears_only_when_there_is_something_to_open(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me, ['message' => 'Has a target', 'provider_uuid' => 'prov-77']);

        $this->actingAs($me, 'web')->get(route('admin.notifications.index'))->assertSee('admin/providers/prov-77')->assertSee(__('admin.notifications.open'));

        $plain = $this->makeAdmin('admin');
        $this->giveNotification($plain, ['message' => 'No target']);
        $this->actingAs($plain, 'web')->get(route('admin.notifications.index'))->assertDontSee(__('admin.notifications.open'));
    }

    // --- The sidebar badge ----------------------------------------------------------------

    public function test_the_sidebar_link_shows_how_many_are_unread(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me);
        $this->giveNotification($me);
        $this->giveNotification($me, read: true);

        $page = $this->actingAs($me, 'web')->get(route('admin.dashboard'));

        $page->assertSee(route('admin.notifications.index'));
        $this->assertSame(1, preg_match('#<span\s+class="ms-auto[^>]*>\s*2\s*</span>#', $page->getContent()), 'The badge should read 2.');
    }

    public function test_there_is_no_badge_when_everything_is_read(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me, read: true);

        $page = $this->actingAs($me, 'web')->get(route('admin.dashboard'));

        $this->assertSame(0, preg_match('#<span\s+class="ms-auto[^>]*bg-sky-500#', $page->getContent()));
    }

    public function test_the_sidebar_link_is_hidden_from_roles_without_the_permission(): void
    {
        $this->actingAs($this->makeAdmin('content_admin'), 'web')->get(route('admin.dashboard'))->assertDontSee(route('admin.notifications.index'));
    }

    // --- Language --------------------------------------------------------------------------

    public function test_the_inbox_is_fully_translated_in_both_languages(): void
    {
        $me = $this->makeAdmin();
        $this->giveNotification($me, ['message' => 'One', 'provider_uuid' => 'p-1']);
        $this->giveNotification($me, ['message' => 'Two'], read: true);

        $this->inEachAdminLanguage(function (string $locale) use ($me) {
            foreach (['all' => route('admin.notifications.index'), 'unread' => route('admin.notifications.index', ['status' => 'unread'])] as $name => $url) {
                $this->assertNoRawTranslationKeys($this->actingAs($me, 'web')->get($url)->assertOk(), "({$name}, {$locale})");
            }
        });
    }
}
