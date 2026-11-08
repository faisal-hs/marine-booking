<?php

namespace App\Services;

use App\Models\Provider;
use Illuminate\Validation\ValidationException;

/**
 * The single rule behind "every provider is verified before their activities go
 * live" (the claim on the public homepage): only a verified provider can get an
 * activity into the review queue, and only a verified provider's activity can be
 * approved or republished.
 *
 * Drafts are deliberately NOT blocked, so a new provider can build their listing
 * while their documents are being checked. Reading is not blocked either:
 * activities that are already published stay visible, which keeps demo and
 * historical data working.
 */
class ProviderVerificationGate
{
    /**
     * An activity can only go live (or be asked to) while its provider's account is one an admin approved and that is in good standing. The admin
     * approves the PROVIDER; nobody approves the activity.
     */
    public function assertCanPublish(Provider $provider): void
    {
        // An account that was deleted or suspended must not get a listing in front of customers.
        if ($provider->user->trashed()) {
            throw ValidationException::withMessages(['provider' => ["This provider's account has been deleted. Restore it before publishing their activities."]]);
        }

        if ($provider->user->status !== 'active') {
            throw ValidationException::withMessages(['provider' => ["This provider's account is {$provider->user->status}. Reactivate it before publishing their activities."]]);
        }

        if ($provider->isVerified()) {
            return;
        }

        $message = $provider->verification_status === 'rejected'
            ? 'Your account verification was rejected, so you cannot publish activities.'.($provider->rejection_reason ? " Reason: {$provider->rejection_reason}" : '')
            : 'Your account is still waiting for verification. You can publish activities once an admin has verified it.';

        throw ValidationException::withMessages(['provider' => [$message]]);
    }
}
