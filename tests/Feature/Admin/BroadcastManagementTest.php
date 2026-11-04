<?php

namespace Tests\Feature\Admin;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Models\Device;
use App\Models\User;
use App\Notifications\Broadcasts\BroadcastNotification;
use App\Services\BroadcastService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Announcements: compose, send once to a whole audience (or one account), delete.
 * Composing and sending need notifications.create; deleting needs notifications.delete.
 */
class BroadcastManagementTest extends TestCase
{
    use AssertsTranslated;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function asAdmin(string $role = 'super_admin'): static
    {
        return $this->actingAs($this->makeAdmin($role), 'web');
    }

    private function draft(array $overrides = []): Broadcast
    {
        return Broadcast::create(array_merge([
            'title' => 'Winter schedule', 'body' => 'Boats leave at 9 from December.', 'audience' => 'customers',
            'status' => 'draft', 'created_by' => $this->makeAdmin()->id,
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['title' => 'Winter schedule', 'body' => 'Boats leave at 9 from December.', 'audience' => 'customers'], $overrides);
    }

    private function received(User $user): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->where('type', BroadcastNotification::class)->count();
    }

    // --- Who may do what ---------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function permissionMatrix(): array
    {
        $actions = ['index', 'show', 'create', 'store', 'edit', 'update', 'send', 'destroy'];
        $allowed = ['super_admin' => $actions, 'admin' => $actions, 'support_admin' => ['index', 'show'], 'content_admin' => [], 'none' => []];
        $cases = [];

        foreach ($allowed as $role => $may) {
            foreach ($actions as $action) {
                $cases["{$role} / {$action}"] = [$role, $action, in_array($action, $may, true) ? 'ok' : 'denied'];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionMatrix')]
    public function test_each_action_needs_its_permission(string $role, string $action, string $expected): void
    {
        $this->makeCustomer(); // someone for a send to reach
        $actor = $this->makeAdmin('super_admin');
        $actor->syncRoles($role === 'none' ? [] : [$role]);
        $broadcast = $this->draft();

        $client = $this->actingAs($actor, 'web');
        $response = match ($action) {
            'index' => $client->get(route('admin.notifications.broadcasts.index')),
            'show' => $client->get(route('admin.notifications.broadcasts.show', $broadcast)),
            'create' => $client->get(route('admin.notifications.broadcasts.create')),
            'store' => $client->post(route('admin.notifications.broadcasts.store'), $this->payload()),
            'edit' => $client->get(route('admin.notifications.broadcasts.edit', $broadcast)),
            'update' => $client->put(route('admin.notifications.broadcasts.update', $broadcast), $this->payload(['title' => 'Changed'])),
            'send' => $client->post(route('admin.notifications.broadcasts.send', $broadcast)),
            'destroy' => $client->delete(route('admin.notifications.broadcasts.destroy', $broadcast)),
        };

        if ($expected === 'denied') {
            $this->assertContains($response->getStatusCode(), [302, 403], "{$role} must not be able to {$action}");
            if ($role !== 'none') {
                $response->assertForbidden();
            }
        } else {
            $this->assertContains($response->getStatusCode(), [200, 302], "{$role} should be able to {$action}");
            $this->assertNotSame(403, $response->getStatusCode());
        }
    }

    public function test_a_denied_send_really_sends_nothing(): void
    {
        [$customerUser] = $this->makeCustomer();
        $broadcast = $this->draft();

        $this->asAdmin('support_admin')->post(route('admin.notifications.broadcasts.send', $broadcast))->assertForbidden();

        $this->assertSame('draft', $broadcast->fresh()->status);
        $this->assertSame(0, $this->received($customerUser));
    }

    // --- Composing -----------------------------------------------------------------

    public function test_an_admin_can_save_a_draft_for_every_customer(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), $this->payload());

        $broadcast = Broadcast::firstOrFail();
        $response->assertRedirect(route('admin.notifications.broadcasts.show', $broadcast));
        $this->assertSame('draft', $broadcast->status);
        $this->assertSame('customers', $broadcast->audience);
        $this->assertSame($admin->id, $broadcast->created_by);
        $this->assertNull($broadcast->recipient_user_id);
        $this->assertSame(0, DB::table('notifications')->count(), 'Saving a draft must send nothing.');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'no title' => [['title' => ''], 'title'],
            'a title over 120 characters' => [['title' => str_repeat('t', 121)], 'title'],
            'no message' => [['body' => ''], 'body'],
            'a message over 1000 characters' => [['body' => str_repeat('b', 1001)], 'body'],
            'no audience' => [['audience' => ''], 'audience'],
            'an unknown audience' => [['audience' => 'everyone'], 'audience'],
            'one account but nobody named' => [['audience' => 'user'], 'recipient'],
            'one account that does not exist' => [['audience' => 'user', 'recipient' => 'nobody@nowhere.test'], 'recipient'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_and_nothing_is_saved(array $overrides, string $field): void
    {
        $this->asAdmin()->post(route('admin.notifications.broadcasts.store'), $this->payload($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Broadcast::count());
    }

    public function test_a_single_account_can_be_named_by_phone_or_by_email(): void
    {
        [$user] = $this->makeCustomer(['phone' => '0533777666', 'email' => 'named@mail.test']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), $this->payload(['audience' => 'user', 'recipient' => '0533777666']))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), $this->payload(['audience' => 'user', 'recipient' => 'named@mail.test']))->assertSessionHasNoErrors();

        $this->assertSame([$user->id, $user->id], Broadcast::pluck('recipient_user_id')->all());
    }

    public function test_a_single_recipient_must_be_an_active_customer_or_provider(): void
    {
        $staff = $this->makeAdmin('admin');
        $staff->update(['email' => 'staff@mail.test']);
        [$suspended] = $this->makeCustomer(['email' => 'suspended@mail.test']);
        $suspended->update(['status' => 'suspended']);
        [$deleted] = $this->makeCustomer(['email' => 'deleted@mail.test']);
        $deleted->delete();
        $admin = $this->makeAdmin();

        foreach (['staff@mail.test', 'suspended@mail.test', 'deleted@mail.test'] as $email) {
            $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), $this->payload(['audience' => 'user', 'recipient' => $email]))
                ->assertSessionHasErrors('recipient');
        }
        $this->assertSame(0, Broadcast::count());
    }

    public function test_the_recipient_is_ignored_unless_the_audience_is_one_account(): void
    {
        [$user] = $this->makeCustomer(['email' => 'ignored@mail.test']);

        $this->asAdmin()->post(route('admin.notifications.broadcasts.store'), $this->payload(['audience' => 'customers', 'recipient' => 'ignored@mail.test']));

        $this->assertNull(Broadcast::firstOrFail()->recipient_user_id);
    }

    // --- Who it reaches ----------------------------------------------------------------

    public function test_the_audience_is_only_active_customers_or_providers(): void
    {
        [$customer] = $this->makeCustomer();
        [$suspendedCustomer] = $this->makeCustomer();
        $suspendedCustomer->update(['status' => 'suspended']);
        [$deletedCustomer] = $this->makeCustomer();
        $deletedCustomer->delete();
        [$provider] = $this->makeProvider('verified');
        $staff = $this->makeAdmin('admin');
        $service = app(BroadcastService::class);

        $customers = $service->recipients($this->draft(['audience' => 'customers']))->pluck('id')->all();
        $providers = $service->recipients($this->draft(['audience' => 'providers']))->pluck('id')->all();
        $one = $service->recipients($this->draft(['audience' => 'user', 'recipient_user_id' => $customer->id]))->pluck('id')->all();

        $this->assertSame([$customer->id], $customers);
        $this->assertSame([$provider->id], $providers);
        $this->assertSame([$customer->id], $one);
        $this->assertNotContains($staff->id, array_merge($customers, $providers, $one));
    }

    public function test_a_draft_shows_how_many_accounts_a_send_would_reach(): void
    {
        $this->makeCustomer();
        $this->makeCustomer();
        [$gone] = $this->makeCustomer();
        $gone->delete();
        $broadcast = $this->draft();

        $this->asAdmin()->get(route('admin.notifications.broadcasts.show', $broadcast))
            ->assertSee(__('admin.notifications.would_reach', ['count' => 2]));
    }

    // --- Editing -----------------------------------------------------------------------

    public function test_a_draft_can_be_edited(): void
    {
        $broadcast = $this->draft();

        $this->asAdmin()->put(route('admin.notifications.broadcasts.update', $broadcast), $this->payload(['title' => 'New title', 'audience' => 'providers']))
            ->assertRedirect(route('admin.notifications.broadcasts.show', $broadcast));

        $broadcast->refresh();
        $this->assertSame('New title', $broadcast->title);
        $this->assertSame('providers', $broadcast->audience);
    }

    public function test_a_sent_broadcast_can_no_longer_be_edited(): void
    {
        $this->makeCustomer();
        $broadcast = $this->draft();
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast));
        $this->assertSame('sent', $broadcast->fresh()->status);

        $this->actingAs($admin, 'web')->get(route('admin.notifications.broadcasts.edit', $broadcast))->assertRedirect(route('admin.notifications.broadcasts.show', $broadcast))->assertSessionHas('error');
        $this->actingAs($admin, 'web')->put(route('admin.notifications.broadcasts.update', $broadcast), $this->payload(['title' => 'Rewriting history']))->assertSessionHas('error');

        $this->assertSame('Winter schedule', $broadcast->fresh()->title);
    }

    // --- Sending ------------------------------------------------------------------------

    public function test_sending_reaches_every_active_customer_once_and_only_them(): void
    {
        [$a] = $this->makeCustomer();
        [$b] = $this->makeCustomer();
        [$suspended] = $this->makeCustomer();
        $suspended->update(['status' => 'suspended']);
        [$deleted] = $this->makeCustomer();
        $deleted->delete();
        [$provider] = $this->makeProvider('verified');
        $staff = $this->makeAdmin('admin');
        $broadcast = $this->draft(['title' => 'Winter schedule', 'body' => 'Boats leave at 9 from December.']);

        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $broadcast))->assertRedirect(route('admin.notifications.broadcasts.show', $broadcast));

        $this->assertSame(1, $this->received($a));
        $this->assertSame(1, $this->received($b));
        foreach ([$suspended, $deleted, $provider, $staff] as $notReached) {
            $this->assertSame(0, $this->received($notReached));
        }
        $broadcast->refresh();
        $this->assertSame('sent', $broadcast->status);
        $this->assertSame(2, $broadcast->recipients_count);
        $this->assertSame(2, $broadcast->delivered_count);
        $this->assertNotNull($broadcast->sent_at);

        $data = json_decode(DB::table('notifications')->where('notifiable_id', $a->id)->value('data'), true);
        $this->assertSame('broadcast', $data['type']);
        $this->assertSame('Winter schedule', $data['title']);
        $this->assertSame('Boats leave at 9 from December.', $data['message']);
        $this->assertSame($broadcast->uuid, $data['broadcast_uuid']);
    }

    public function test_sending_to_providers_and_to_one_account(): void
    {
        [$provider] = $this->makeProvider('verified');
        [$customer] = $this->makeCustomer();
        [$target] = $this->makeCustomer();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $this->draft(['audience' => 'providers'])));
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $this->draft(['audience' => 'user', 'recipient_user_id' => $target->id, 'title' => 'Just for you'])));

        $this->assertSame(1, $this->received($provider));
        $this->assertSame(1, $this->received($target));
        $this->assertSame(0, $this->received($customer), 'A named account must be the only one reached.');
    }

    public function test_customers_and_providers_see_it_in_their_notification_feed_in_the_app(): void
    {
        [$customerUser] = $this->makeCustomer();
        $token = $customerUser->createToken('t', ['customer'])->plainTextToken;
        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $this->draft(['title' => 'Hello customers'])));
        // Sanctum looks at the web guard first, so forget the admin this test just acted as.
        $this->app['auth']->forgetGuards();

        $feed = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/customer/notifications')->assertOk();

        $this->assertSame('broadcast', $feed->json('data.0.type'));
        $this->assertSame('Hello customers', $feed->json('data.0.data.title'));
    }

    public function test_each_recipient_also_gets_a_push_on_their_device(): void
    {
        // Bound before anything is created: the push channel keeps the gateway it was first built with.
        $gateway = new class implements FcmGatewayInterface
        {
            public array $sent = [];

            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                $this->sent[] = compact('token', 'title', 'body', 'data');

                return true;
            }
        };
        $this->app->instance(FcmGatewayInterface::class, $gateway);

        [$withDevice] = $this->makeCustomer();
        [$noDevice] = $this->makeCustomer();
        Device::create(['user_id' => $withDevice->id, 'fcm_token' => 'customer-device', 'platform' => 'android']);

        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $this->draft(['title' => 'Push me', 'body' => str_repeat('long ', 80)])));

        $this->assertCount(1, $gateway->sent, 'Only the customer with a device is pushed to.');
        $this->assertSame('customer-device', $gateway->sent[0]['token']);
        $this->assertSame('Push me', $gateway->sent[0]['title']);
        $this->assertSame('broadcast', $gateway->sent[0]['data']['type']);
        $this->assertLessThanOrEqual(180, mb_strlen($gateway->sent[0]['body']), 'A push body is shortened.');
        $this->assertSame(1, $this->received($noDevice), 'The in-app entry does not depend on a device.');
    }

    public function test_a_broadcast_can_only_be_sent_once(): void
    {
        [$customer] = $this->makeCustomer();
        $broadcast = $this->draft();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast))->assertSessionHas('status');
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast))->assertSessionHas('error');

        $this->assertSame(1, $this->received($customer));
    }

    public function test_sending_when_there_is_nobody_to_send_to_is_refused(): void
    {
        $broadcast = $this->draft(['audience' => 'providers']);

        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $broadcast))->assertSessionHas('error');

        $this->assertSame('draft', $broadcast->fresh()->status, 'It stays a draft, ready for when there are recipients.');
    }

    public function test_the_send_is_queued_and_does_not_run_in_the_web_request(): void
    {
        Queue::fake();
        [$customer] = $this->makeCustomer();
        $broadcast = $this->draft();

        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $broadcast));

        Queue::assertPushed(SendBroadcast::class, 1);
        $broadcast->refresh();
        $this->assertSame('sending', $broadcast->status);
        $this->assertSame(1, $broadcast->recipients_count);
        $this->assertSame(0, $this->received($customer), 'Nothing is delivered until the job runs.');
    }

    public function test_two_sends_in_a_row_queue_only_one_job(): void
    {
        Queue::fake();
        $this->makeCustomer();
        $broadcast = $this->draft();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast));
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast))->assertSessionHas('error');

        Queue::assertPushed(SendBroadcast::class, 1);
    }

    public function test_a_second_click_made_from_a_stale_page_cannot_send_it_again(): void
    {
        // The race: two admins (or two clicks) both loaded the draft while it was still a draft.
        // The in-memory status check passes for both, so only the atomic UPDATE in send() can
        // stop the second one. Sequential tests cannot see that, which is why this one holds
        // two stale copies of the same row.
        Queue::fake();
        $this->makeCustomer();
        $id = $this->draft()->id;
        $firstClick = Broadcast::find($id);
        $secondClick = Broadcast::find($id);
        $service = app(BroadcastService::class);

        $service->send($firstClick);

        try {
            $service->send($secondClick);
            $this->fail('The second send must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('send', $e->errors());
        }

        Queue::assertPushed(SendBroadcast::class, 1);
    }

    public function test_running_the_job_again_never_messages_anyone_twice(): void
    {
        [$customer] = $this->makeCustomer();
        $broadcast = $this->draft();
        $broadcast->update(['status' => 'sending', 'recipients_count' => 1]);

        $service = app(BroadcastService::class);
        $service->deliver($broadcast->id);
        // A restarted job (it crashed after handing over, say) must not repeat anyone.
        $broadcast->update(['status' => 'sending']);
        $service->deliver($broadcast->id);

        $this->assertSame(1, $this->received($customer));
    }

    public function test_a_job_for_a_broadcast_that_is_not_sending_does_nothing(): void
    {
        [$customer] = $this->makeCustomer();
        $broadcast = $this->draft();

        app(BroadcastService::class)->deliver($broadcast->id);
        app(BroadcastService::class)->deliver(999999);

        $this->assertSame(0, $this->received($customer));
        $this->assertSame('draft', $broadcast->fresh()->status);
    }

    public function test_a_failed_job_marks_the_broadcast_failed_and_says_why(): void
    {
        $broadcast = $this->draft();
        $broadcast->update(['status' => 'sending']);

        (new SendBroadcast($broadcast->id))->failed(new \RuntimeException('The queue worker lost its connection'));

        $broadcast->refresh();
        $this->assertSame('failed', $broadcast->status);
        $this->assertSame('The queue worker lost its connection', $broadcast->failed_reason);

        $this->asAdmin()->get(route('admin.notifications.broadcasts.show', $broadcast))->assertSee('The queue worker lost its connection');
        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $broadcast))->assertSessionHas('error');
    }

    public function test_one_recipient_that_cannot_be_notified_does_not_stop_the_others(): void
    {
        [$first] = $this->makeCustomer();
        [$second] = $this->makeCustomer();
        // A notification that explodes for one recipient is reported and skipped by the dispatch service.
        $this->app->bind(Dispatcher::class, function ($app) use ($first) {
            $real = new ChannelManager($app);

            return new class($real, $first) implements Dispatcher
            {
                public function __construct(private $real, private $bad) {}

                public function send($notifiables, $notification): void
                {
                    if ($notifiables->is($this->bad)) {
                        throw new \RuntimeException('this recipient is broken');
                    }
                    $this->real->send($notifiables, $notification);
                }

                public function sendNow($notifiables, $notification, ?array $channels = null): void
                {
                    $this->real->sendNow($notifiables, $notification, $channels);
                }
            };
        });
        $broadcast = $this->draft();

        $this->asAdmin()->post(route('admin.notifications.broadcasts.send', $broadcast));

        $this->assertSame(1, $this->received($second), 'The healthy recipient is still reached.');
        $broadcast->refresh();
        $this->assertSame('sent', $broadcast->status);
        $this->assertSame(1, $broadcast->delivered_count, 'Only the hand-offs that worked are counted.');
        $this->assertSame(2, $broadcast->recipients_count);
    }

    // --- Deleting -----------------------------------------------------------------------

    public function test_a_draft_can_be_deleted(): void
    {
        $broadcast = $this->draft();

        $this->asAdmin('admin')->delete(route('admin.notifications.broadcasts.destroy', $broadcast))->assertRedirect(route('admin.notifications.broadcasts.index'));

        $this->assertDatabaseMissing('broadcasts', ['id' => $broadcast->id]);
    }

    public function test_deleting_a_sent_broadcast_removes_the_record_but_not_what_was_delivered(): void
    {
        [$customer] = $this->makeCustomer();
        $broadcast = $this->draft();
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast));

        $this->actingAs($admin, 'web')->delete(route('admin.notifications.broadcasts.destroy', $broadcast))->assertSessionHas('status');

        $this->assertDatabaseMissing('broadcasts', ['id' => $broadcast->id]);
        $this->assertSame(1, $this->received($customer), 'Notifications already in feeds stay there.');
    }

    public function test_a_broadcast_that_is_being_sent_cannot_be_deleted(): void
    {
        $broadcast = $this->draft();
        $broadcast->update(['status' => 'sending']);

        $this->asAdmin()->delete(route('admin.notifications.broadcasts.destroy', $broadcast))->assertSessionHas('error');

        $this->assertDatabaseHas('broadcasts', ['id' => $broadcast->id]);
    }

    public function test_deleting_the_broadcast_while_messages_are_still_queued_does_not_break_them(): void
    {
        // The queued notification carries plain text, not the Broadcast model.
        [$customer] = $this->makeCustomer();

        $notification = new BroadcastNotification('Title', 'Body', 'uuid-of-a-deleted-broadcast');
        $restored = unserialize(serialize($notification));

        $this->assertInstanceOf(BroadcastNotification::class, $restored);
        $this->assertSame(['type' => 'broadcast', 'title' => 'Title', 'message' => 'Body', 'broadcast_uuid' => 'uuid-of-a-deleted-broadcast'], $restored->toArray($customer));
    }

    // --- The list and the pages ---------------------------------------------------------------

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->draft(['title' => 'A draft one']);
        $this->draft(['title' => 'A sent one', 'status' => 'sent', 'recipients_count' => 3, 'delivered_count' => 3]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->get(route('admin.notifications.broadcasts.index'))->assertSee('A draft one')->assertSee('A sent one');
        $this->actingAs($admin, 'web')->get(route('admin.notifications.broadcasts.index', ['status' => 'sent']))->assertSee('A sent one')->assertDontSee('A draft one');
        $this->actingAs($admin, 'web')->get(route('admin.notifications.broadcasts.index', ['status' => 'bogus']))->assertOk()->assertSee('A draft one');
    }

    public function test_the_buttons_follow_the_permissions(): void
    {
        $broadcast = $this->draft();

        $admin = $this->asAdmin('admin')->get(route('admin.notifications.broadcasts.show', $broadcast));
        $admin->assertSee(route('admin.notifications.broadcasts.send', $broadcast))->assertSee(route('admin.notifications.broadcasts.edit', $broadcast))->assertSee('value="DELETE"', false);

        $support = $this->asAdmin('support_admin')->get(route('admin.notifications.broadcasts.show', $broadcast));
        $support->assertOk()->assertDontSee(route('admin.notifications.broadcasts.send', $broadcast))->assertDontSee(route('admin.notifications.broadcasts.edit', $broadcast))->assertDontSee('value="DELETE"', false);
    }

    public function test_arabic_text_is_kept_and_shown_and_html_is_escaped(): void
    {
        [$customerUser] = $this->makeCustomer();
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.store'), $this->payload([
            'title' => 'جدول الشتاء <b>مهم</b>', 'body' => "تغادر القوارب في التاسعة.\n<script>alert(1)</script>",
        ]));
        $broadcast = Broadcast::firstOrFail();

        $page = $this->actingAs($admin, 'web')->get(route('admin.notifications.broadcasts.show', $broadcast));
        $page->assertOk()->assertSee('جدول الشتاء')->assertSee('تغادر القوارب في التاسعة.')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);

        $this->actingAs($admin, 'web')->post(route('admin.notifications.broadcasts.send', $broadcast));
        $data = json_decode(DB::table('notifications')->where('notifiable_id', $customerUser->id)->value('data'), true);
        $this->assertStringContainsString('تغادر القوارب في التاسعة.', $data['message']);
    }

    public function test_the_notification_is_queued_with_the_shared_retry_policy(): void
    {
        $this->assertTrue(is_subclass_of(BroadcastNotification::class, ShouldQueue::class));

        $defaults = (new ReflectionClass(BroadcastNotification::class))->getDefaultProperties();
        $this->assertSame(3, $defaults['tries']);
        $this->assertSame([30, 120], $defaults['backoff']);
        $this->assertSame(['database', 'fcm'], (new BroadcastNotification('t', 'b', 'u'))->via(new User));
    }

    public function test_every_announcement_screen_is_fully_translated_in_both_languages(): void
    {
        $this->makeCustomer();
        $draft = $this->draft();
        $sent = $this->draft(['status' => 'sent', 'recipients_count' => 2, 'delivered_count' => 2, 'sent_at' => now()]);
        $failed = $this->draft(['status' => 'failed', 'failed_reason' => 'Queue unavailable']);
        $admin = $this->makeAdmin();

        $this->inEachAdminLanguage(function (string $locale) use ($admin, $draft, $sent, $failed) {
            $pages = [
                'index' => route('admin.notifications.broadcasts.index'),
                'create' => route('admin.notifications.broadcasts.create'),
                'edit' => route('admin.notifications.broadcasts.edit', $draft),
                'show draft' => route('admin.notifications.broadcasts.show', $draft),
                'show sent' => route('admin.notifications.broadcasts.show', $sent),
                'show failed' => route('admin.notifications.broadcasts.show', $failed),
            ];
            foreach ($pages as $name => $url) {
                $this->assertNoRawTranslationKeys($this->actingAs($admin, 'web')->get($url)->assertOk(), "({$name}, {$locale})");
            }
        });
    }
}
