<?php

namespace App\Services;

use App\Events\ProviderRejected;
use App\Events\ProviderVerified;
use App\Models\Provider;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Illuminate\Validation\ValidationException;

class ProviderVerificationService
{
    public function __construct(protected ProviderRepositoryInterface $repository) {}

    public function verify(Provider $provider): Provider
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

        event(new ProviderVerified($provider));

        return $provider;
    }

    public function reject(Provider $provider, string $reason): Provider
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

        event(new ProviderRejected($provider));

        return $provider;
    }
}
