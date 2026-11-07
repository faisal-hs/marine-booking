<?php

namespace App\Notifications\Wallet\Admin;

use App\Models\Withdrawal;
use Illuminate\Notifications\Notification;

/** Tells the admins who pay out that a provider is waiting to be paid. */
class WithdrawalRequestedAdminNotification extends Notification
{
    public function __construct(protected Withdrawal $withdrawal) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $provider = $this->withdrawal->provider;

        return [
            'type' => 'admin.withdrawal.requested',
            'withdrawal_uuid' => $this->withdrawal->uuid,
            'provider_uuid' => $provider->uuid,
            'business_name' => $provider->business_name,
            'amount' => $this->withdrawal->amount,
            'currency' => $this->withdrawal->currency,
            'message' => "\"{$provider->business_name}\" asked to withdraw {$this->withdrawal->amount} {$this->withdrawal->currency}.",
        ];
    }
}
