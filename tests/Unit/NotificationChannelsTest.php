<?php

namespace Tests\Unit;

use App\Contracts\Fcm\FcmGatewayInterface;
use App\Models\Device;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Mockery;
use Tests\TestCase;

class SmsCapableTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): string
    {
        return 'Test SMS body';
    }
}

class NoSmsMethodTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }
}

class FcmCapableTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['fcm'];
    }

    public function toFcm(object $notifiable): array
    {
        return ['title' => 'Test Title', 'body' => 'Test Body', 'data' => ['key' => 'value']];
    }
}

class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_channel_sends_via_the_existing_gateway_when_phone_present(): void
    {
        $user = User::factory()->customer()->create(['phone' => '+201234567890']);

        $mockGateway = Mockery::mock(SmsGatewayInterface::class);
        $mockGateway->shouldReceive('send')->once()->with('+201234567890', 'Test SMS body')->andReturn(true);

        $channel = new SmsChannel($mockGateway);
        $channel->send($user, new SmsCapableTestNotification());
    }

    public function test_sms_channel_no_ops_when_notification_lacks_toSms(): void
    {
        $user = User::factory()->customer()->create(['phone' => '+201234567890']);

        $mockGateway = Mockery::mock(SmsGatewayInterface::class);
        $mockGateway->shouldNotReceive('send');

        $channel = new SmsChannel($mockGateway);
        $channel->send($user, new NoSmsMethodTestNotification());
    }

    public function test_sms_channel_no_ops_when_notifiable_has_no_phone(): void
    {
        $user = User::factory()->customer()->create(['phone' => null]);

        $mockGateway = Mockery::mock(SmsGatewayInterface::class);
        $mockGateway->shouldNotReceive('send');

        $channel = new SmsChannel($mockGateway);
        $channel->send($user, new SmsCapableTestNotification());
    }

    public function test_fcm_channel_sends_to_every_registered_device(): void
    {
        $user = User::factory()->customer()->create();
        Device::create(['user_id' => $user->id, 'fcm_token' => 'token-1', 'platform' => 'ios']);
        Device::create(['user_id' => $user->id, 'fcm_token' => 'token-2', 'platform' => 'android']);

        $mockGateway = Mockery::mock(FcmGatewayInterface::class);
        $mockGateway->shouldReceive('send')->once()->with('token-1', 'Test Title', 'Test Body', ['key' => 'value'])->andReturn(true);
        $mockGateway->shouldReceive('send')->once()->with('token-2', 'Test Title', 'Test Body', ['key' => 'value'])->andReturn(true);

        $channel = new FcmChannel($mockGateway);
        $channel->send($user->fresh(), new FcmCapableTestNotification());
    }

    public function test_fcm_channel_no_ops_when_no_devices_registered(): void
    {
        $user = User::factory()->customer()->create();

        $mockGateway = Mockery::mock(FcmGatewayInterface::class);
        $mockGateway->shouldNotReceive('send');

        $channel = new FcmChannel($mockGateway);
        $channel->send($user, new FcmCapableTestNotification());
    }
}
