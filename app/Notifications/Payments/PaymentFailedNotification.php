<?php

namespace App\Notifications\Payments;

/**
 * Channel matrix decision: database + fcm + sms. Distinct from a routine
 * "reservation confirmed" moment — a failed payment is actionable (their
 * reservation is still pending and the hold may expire), which is
 * exactly the "real business value" bar the agreed SMS guidance sets.
 * No email — this needs an immediate reaction, not a formal record.
 */
class PaymentFailedNotification extends BasePaymentNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'sms'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'payment.failed',
            "Your payment of {$this->payment->amount} {$this->payment->currency} could not be completed."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Payment Failed',
            'body' => 'Your payment could not be completed. Please try again before your reservation hold expires.',
            'data' => ['payment_id' => (string) $this->payment->id, 'type' => 'payment.failed'],
        ];
    }

    public function toSms(object $notifiable): string
    {
        return "Marine Booking: Your payment of {$this->payment->amount} {$this->payment->currency} failed. Please retry before your reservation hold expires.";
    }
}
