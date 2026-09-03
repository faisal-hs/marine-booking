<?php

namespace App\Notifications\Payments;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

abstract class BasePaymentNotification extends Notification
{
    public function __construct(protected Payment $payment) {}

    protected function baseData(string $type, string $message): array
    {
        return [
            'type' => $type,
            'payment_id' => $this->payment->id,
            'payment_uuid' => $this->payment->uuid,
            'reference_id' => $this->payment->reference_id,
            'amount' => (float) $this->payment->amount,
            'currency' => $this->payment->currency,
            'message' => $message,
        ];
    }
}
