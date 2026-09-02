<?php

namespace App\Notifications\Channels;

use App\Contracts\Fcm\FcmGatewayInterface;
use Illuminate\Notifications\Notification;

/**
 * Reuses the EXISTING devices table/relationship (User::devices()) —
 * sends to every registered device token for the notifiable, since a
 * user may be logged in on multiple devices simultaneously.
 *
 * A Notification class opts in via toFcm($notifiable): array returning
 * ['title' => ..., 'body' => ..., 'data' => [...]]. Silently no-ops if
 * missing, or if the notifiable has zero registered devices.
 */
class FcmChannel
{
    public function __construct(protected FcmGatewayInterface $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        if (! method_exists($notifiable, 'devices')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        foreach ($notifiable->devices as $device) {
            if ($device->fcm_token) {
                $this->gateway->send($device->fcm_token, $payload['title'], $payload['body'], $payload['data'] ?? []);
            }
        }
    }
}
