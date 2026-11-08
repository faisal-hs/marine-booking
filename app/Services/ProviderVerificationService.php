<?php

namespace App\Services;

use App\Events\ProviderRejected;
use App\Events\ProviderVerified;
use App\Models\Provider;
use App\Models\ProviderRequest;
use App\Models\User;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Illuminate\Validation\ValidationException;

class ProviderVerificationService
{
    public function __construct(
        protected ProviderRepositoryInterface $repository,
        protected ProviderDocumentReviewService $documentReview,
    ) {}

    public function verify(Provider $provider, ?User $reviewer = null): Provider
    {
        if ($provider->verification_status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Only pending providers can be verified.'],
            ]);
        }

        $provider = $this->repository->update($provider, [
            'verification_status' => 'verified',
            'rejection_reason' => null,
        ]);

        $this->closePendingRequest($provider, 'approved', $reviewer, null);

        // The documents the admin verified the provider on are approved with it, and say so in their history.
        $this->documentReview->approveAllPending($provider, $reviewer);

        event(new ProviderVerified($provider));

        return $provider;
    }

    public function reject(Provider $provider, string $reason, ?User $reviewer = null): Provider
    {
        if ($provider->verification_status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Only pending providers can be rejected.'],
            ]);
        }

        $provider = $this->repository->update($provider, [
            'verification_status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        $this->closePendingRequest($provider, 'rejected', $reviewer, $reason);

        event(new ProviderRejected($provider));

        return $provider;
    }

    /**
     * Whichever way the decision is taken (the provider requests screen or the provider's own page),
     * the pending request it settles is closed with it, so the two can never disagree.
     */
    protected function closePendingRequest(Provider $provider, string $status, ?User $reviewer, ?string $reason): void
    {
        ProviderRequest::where('provider_id', $provider->id)->where('status', 'pending')->update([
            'status' => $status,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer?->id,
            'rejection_reason' => $reason,
        ]);
    }
}
