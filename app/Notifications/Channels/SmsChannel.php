<?php

namespace App\Notifications\Channels;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use Illuminate\Notifications\Notification;

/**
 * Reuses the EXISTING SmsGatewayInterface binding (from Module 01's OTP
 * infrastructure) rather than introducing a second SMS integration —
 * whichever driver is bound there (LogSmsGateway locally, TaqnyatSmsGateway
 * in production) is exactly what this channel uses too.
 *
 * A Notification class opts into this channel by including it in via()
 * AND implementing toSms($notifiable): string. Silently no-ops if either
 * is missing, or if the notifiable has no phone number — this channel
 * never throws for a recipient that simply can't receive SMS.
 */
class SmsChannel
{
    public function __construct(protected SmsGatewayInterface $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            return;
        }

        $message = $notification->toSms($notifiable);

        $this->gateway->send($phone, $message);
    }
}
