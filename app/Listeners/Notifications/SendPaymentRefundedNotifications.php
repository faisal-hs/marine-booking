<?php

namespace App\Listeners\Notifications;

use App\Events\PaymentRefunded;
use App\Models\User;
use App\Notifications\Payments\Admin\PaymentRefundedAdminNotification;
use App\Notifications\Payments\PaymentRefundedNotification;
use App\Services\NotificationDispatchService;

class SendPaymentRefundedNotifications
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function handle(PaymentRefunded $event): void
    {
        $payment = $event->payment;

        $this->dispatcher->dispatchOnce(
            "payment.refunded:{$payment->id}",
            $payment->reservation->customer->user,
            new PaymentRefundedNotification($payment)
        );

        foreach ($this->resolveAdminAudience() as $admin) {
            $this->dispatcher->dispatchOnce(
                "admin.payment.refunded:{$payment->id}",
                $admin,
                new PaymentRefundedAdminNotification($payment)
            );
        }
    }

    protected function resolveAdminAudience()
    {
        try {
            return User::permission('payments.view')->get();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return collect();
        }
    }
}
