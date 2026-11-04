<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\UpdateProfileRequest;
use App\Http\Resources\Provider\ProviderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends BaseApiController
{
    /** The profile plus which account this is, the default one, which can be switched to, and the request status. */
    private function withAccount(\App\Models\User $user, ProviderResource $profile): array
    {
        return $profile->resolve(request()) + ['account' => app(\App\Services\AccountService::class)->context($user, 'provider')];
    }

    public function show(Request $request)
    {
        return $this->success($this->withAccount($request->user(), new ProviderResource($request->user()->provider)), 'Profile retrieved.');
    }

    public function update(UpdateProfileRequest $request)
    {
        $data = $request->payload();
        $user = $request->user();

        DB::transaction(function () use ($data, $user) {
            $user->update(array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
            ], fn($v) => $v !== null));

            $user->provider->update(array_filter([
                'business_name' => $data['business_name'] ?? null,
                'bio' => $data['bio'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'area' => $data['area'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ], fn($v) => $v !== null));
        });

        return $this->success($this->withAccount($user, new ProviderResource($user->fresh()->provider)), 'Profile updated successfully.');
    }
}
