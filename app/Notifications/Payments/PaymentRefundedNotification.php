<?php

namespace App\Notifications\Payments;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Channel matrix decision: database + fcm + mail. A refund is a formal
 * financial event worth an email record, matching the agreed "email for
 * formal/important... payment/refund notifications" guidance. No SMS —
 * unlike a failed payment, a refund isn't time-sensitive/actionable for
 * the customer, so SMS wouldn't add real business value here.
 */
class PaymentRefundedNotification extends BasePaymentNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->baseData(
            'payment.refunded',
            "You have been refunded {$this->payment->refunded_amount} {$this->payment->currency}."
        );
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Refund Processed',
            'body' => "You've been refunded {$this->payment->refunded_amount} {$this->payment->currency}.",
            'data' => ['payment_id' => (string) $this->payment->id, 'type' => 'payment.refunded'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Refund Has Been Processed')
            ->greeting("Hi {$notifiable->name},")
            ->line("A refund of {$this->payment->refunded_amount} {$this->payment->currency} has been processed for your payment {$this->payment->reference_id}.")
            ->line('It may take a few business days to reflect in your account.');
    }
}
