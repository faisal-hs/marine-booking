<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the result of an Auth Service call: ['user' => User, 'token' => string,
 * plus one of 'provider'|'customer'|'admin' => the role-specific profile model].
 *
 * BREAKING CHANGE from the previous shape: role-specific profile fields are
 * now flattened directly into the top-level 'user' object (with a 'type'
 * field marking the role) instead of living under a separate 'profile' key.
 * The profile model's own id/uuid are exposed under role-prefixed keys
 * (e.g. 'provider_id'/'provider_uuid') to avoid colliding with the user's
 * own 'id'/'uuid'.
 */
class AuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        $userData = [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'status' => $user->status,
        ];

        foreach (['provider', 'customer', 'admin'] as $type) {
            if (isset($this->resource[$type])) {
                $userData = array_merge($userData, ['type' => $type], $this->profileFields($type, $this->resource[$type]));

                break;
            }
        }

        return [
            'user' => $userData,
            'token' => $this->resource['token'],
        ];
    }

    protected function profileFields(string $type, mixed $profile): array
    {
        return match ($type) {
            'provider' => [
                'provider_id' => $profile->id,
                'provider_uuid' => $profile->uuid,
                'business_name' => $profile->business_name,
                'slug' => $profile->slug,
                'verification_status' => $profile->verification_status,
            ],
            'customer' => [
                'customer_id' => $profile->id,
                'customer_uuid' => $profile->uuid,
                'preferred_language' => $profile->preferred_language,
            ],
            'admin' => [
                'admin_id' => $profile->id,
                'admin_uuid' => $profile->uuid,
                'job_title' => $profile->job_title,
            ],
            default => [],
        };
    }
}
