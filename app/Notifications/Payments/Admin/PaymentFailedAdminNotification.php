<?php

namespace App\Notifications\Payments\Admin;

class PaymentFailedAdminNotification extends BasePaymentAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.payment.failed',
            "Payment failed for {$this->payment->reservation->customer->user->name} ({$this->payment->amount} {$this->payment->currency})."
        );
    }
}
