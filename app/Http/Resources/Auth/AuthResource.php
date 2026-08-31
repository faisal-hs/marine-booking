<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Wraps the result of an Auth Service call: ['user' => User, 'token' => string,
 * plus one of 'provider'|'customer'|'admin' => the role-specific profile model].
 */
class AuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        $profile = null;

        foreach (['provider', 'customer', 'admin'] as $type) {
            if (isset($this->resource[$type])) {
                $profile = array_merge(
                    ['type' => $type],
                    Arr::only($this->resource[$type]->toArray(), $this->profileFields($type))
                );

                break;
            }
        }

        return [
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_type' => $user->user_type,
                'status' => $user->status,
            ],
            'profile' => $profile,
            'token' => $this->resource['token'],
        ];
    }

    protected function profileFields(string $type): array
    {
        return match ($type) {
            'provider' => ['uuid', 'business_name', 'slug', 'verification_status'],
            'customer' => ['uuid', 'preferred_language'],
            'admin' => ['uuid', 'job_title'],
            default => [],
        };
    }
}
