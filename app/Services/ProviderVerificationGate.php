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
    public function assertVerifiedForSubmission(Provider $provider): void
    {
        if ($provider->isVerified()) {
            return;
        }

        $message = $provider->verification_status === 'rejected'
            ? 'Your account verification was rejected, so you cannot submit activities for review.'
            . ($provider->rejection_reason ? " Reason: {$provider->rejection_reason}" : '')
            : 'Your account is still waiting for verification. You can submit activities for review once an admin has verified it.';

        throw ValidationException::withMessages(['provider' => [$message]]);
    }

    public function assertVerifiedForApproval(Provider $provider): void
    {
        if ($provider->isVerified()) {
            return;
        }

        throw ValidationException::withMessages([
            'provider' => ["This provider has not been verified (status: {$provider->verification_status}). Verify the provider before approving their activities."],
        ]);
    }
}
