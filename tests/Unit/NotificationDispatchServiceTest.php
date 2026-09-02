<?php

namespace Tests\Unit;

use App\Services\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use App\Models\User;
use Tests\TestCase;

class DummyTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['message' => 'test'];
    }
}

class NotificationDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_once_sends_a_genuinely_new_notification(): void
    {
        $user = User::factory()->customer()->create();
        $service = app(NotificationDispatchService::class);

        $result = $service->dispatchOnce('test.event:1', $user, new DummyTestNotification());

        $this->assertTrue($result);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notification_dispatch_log', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'event_key' => 'test.event:1',
        ]);
    }

    public function test_dispatch_once_is_idempotent_for_the_same_event_key(): void
    {
        $user = User::factory()->customer()->create();
        $service = app(NotificationDispatchService::class);

        $first = $service->dispatchOnce('test.event:1', $user, new DummyTestNotification());
        $second = $service->dispatchOnce('test.event:1', $user, new DummyTestNotification());

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_different_event_keys_both_dispatch_independently(): void
    {
        $user = User::factory()->customer()->create();
        $service = app(NotificationDispatchService::class);

        $service->dispatchOnce('test.event:1', $user, new DummyTestNotification());
        $service->dispatchOnce('test.event:2', $user, new DummyTestNotification());

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_same_event_key_dispatches_independently_for_different_recipients(): void
    {
        $userA = User::factory()->customer()->create();
        $userB = User::factory()->customer()->create();
        $service = app(NotificationDispatchService::class);

        $resultA = $service->dispatchOnce('test.event:1', $userA, new DummyTestNotification());
        $resultB = $service->dispatchOnce('test.event:1', $userB, new DummyTestNotification());

        $this->assertTrue($resultA);
        $this->assertTrue($resultB);
        $this->assertDatabaseCount('notifications', 2);
    }
}
