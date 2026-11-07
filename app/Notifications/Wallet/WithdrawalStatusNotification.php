<?php

namespace App\Notifications\Wallet;

use App\Models\Withdrawal;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a provider what happened to a withdrawal request: being processed, paid, rejected or failed. */
class WithdrawalStatusNotification extends Notification
{
    public function __construct(protected Withdrawal $withdrawal) {}

    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    private function sentence(): string
    {
        $w = $this->withdrawal;
        $amount = "{$w->amount} {$w->currency}";

        return match ($w->status) {
            Withdrawal::PROCESSING => "Your withdrawal of {$amount} is being processed.",
            Withdrawal::PAID => "Your withdrawal of {$amount} was paid".($w->payout_reference ? " (transfer reference {$w->payout_reference})." : '.'),
            Withdrawal::REJECTED => "Your withdrawal of {$amount} was rejected and returned to your balance.".($w->status_reason ? " Reason: {$w->status_reason}" : ''),
            Withdrawal::FAILED => "Your withdrawal of {$amount} could not be paid and was returned to your balance.".($w->status_reason ? " Reason: {$w->status_reason}" : ''),
            default => "Your withdrawal of {$amount} is {$w->status}.",
        };
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => "provider.withdrawal.{$this->withdrawal->status}", 'withdrawal_uuid' => $this->withdrawal->uuid, 'status' => $this->withdrawal->status, 'message' => $this->sentence()];
    }

    public function toFcm(object $notifiable): array
    {
        return ['title' => 'Withdrawal '.$this->withdrawal->status, 'body' => $this->sentence(), 'data' => ['type' => "provider.withdrawal.{$this->withdrawal->status}"]];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your withdrawal is '.$this->withdrawal->status)->greeting("Hi {$notifiable->name},")->line($this->sentence());
    }
}
