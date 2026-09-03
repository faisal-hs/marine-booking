<?php

namespace App\Listeners\Notifications;

use App\Events\PaymentFailed;
use App\Models\User;
use App\Notifications\Payments\Admin\PaymentFailedAdminNotification;
use App\Notifications\Payments\PaymentFailedNotification;
use App\Services\NotificationDispatchService;

class SendPaymentFailedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(PaymentFailed $event): void
    {
        $payment = $event->payment;

        $this->dispatcher->dispatchOnce(
            "payment.failed:{$payment->id}",
            $payment->reservation->customer->user,
            new PaymentFailedNotification($payment)
        );

        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.payment.failed:{$payment->id}",
                $admin,
                new PaymentFailedAdminNotification($payment)
            );
        }
    }

    /**
     * Defensive against Spatie's permission() scope throwing when the
     * permission record doesn't exist yet — see the identical pattern
     * (and its rationale) in SendReservationCreatedNotifications.
     */
    protected function resolveAdminAudience()
    {
        try {
            return User::permission('payments.view')->get();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return collect();
        }
    }
}
