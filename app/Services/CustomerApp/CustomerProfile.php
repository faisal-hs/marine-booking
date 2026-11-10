<?php

namespace App\Services\CustomerApp;

use App\Models\Customer;
use App\Models\ProviderRequest;

/** A customer's profile as the app shows it. */
class CustomerProfile
{
    public function __construct(private readonly ActivityCatalog $catalog) {}

    /** none, pending, approved or rejected: where this person stands as a provider. */
    public function providerStatus(Customer $customer): string
    {
        $user = $customer->user;
        $provider = $user->provider;

        if ($provider) {
            return $provider->verification_status === 'verified' ? 'approved' : (string) $provider->verification_status;
        }

        return (string) (ProviderRequest::where('user_id', $user->id)->latest('id')->value('status') ?? 'none');
    }

    /** @return array<string, mixed> */
    public function present(Customer $customer): array
    {
        $user = $customer->user;
        $status = $this->providerStatus($customer);

        return [
            'id' => $customer->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $this->catalog->url($user->avatar_path),
            // Verified means the phone: that is how a customer signs in. The email is reported on its own.
            'is_verified' => $user->phone_verified_at !== null,
            'phone_verified' => $user->phone_verified_at !== null,
            'email_verified' => $user->email_verified_at !== null,
            'is_provider' => $status === 'approved',
            'provider_status' => $status,
            'stats' => [
                'trips' => $customer->reservations()->where('status', 'completed')->count(),
                'saved' => $customer->favorites()->count(),
                'reviews' => $customer->reviews()->count(),
            ],
            'notifications' => ['email' => (bool) $customer->notify_email, 'sms' => (bool) $customer->notify_sms, 'marketing' => (bool) $customer->notify_marketing],
        ];
    }
}
