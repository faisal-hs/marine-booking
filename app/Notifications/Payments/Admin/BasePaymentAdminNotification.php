<?php

namespace App\Notifications\Payments\Admin;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

abstract class BasePaymentAdminNotification extends Notification
{
    public function __construct(protected Payment $payment) {}

    protected function baseData(string $type, string $message): array
    {
        return [
            'type' => $type,
            'payment_id' => $this->payment->id,
            'payment_uuid' => $this->payment->uuid,
            'reference_id' => $this->payment->reference_id,
            'customer_name' => $this->payment->reservation->customer->user->name,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'message' => $message,
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }
}
