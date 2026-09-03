<?php

namespace App\Notifications\Payments\Admin;

class PaymentRefundedAdminNotification extends BasePaymentAdminNotification
{
    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'admin.payment.refunded',
            "Refund of {$this->payment->refunded_amount} {$this->payment->currency} processed for {$this->payment->reservation->customer->user->name}."
        );
    }
}
