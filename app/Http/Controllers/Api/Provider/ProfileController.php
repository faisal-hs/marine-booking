<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\UpdateProfileRequest;
use App\Http\Resources\Provider\ProviderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends BaseApiController
{
    public function show(Request $request)
    {
        return $this->success(new ProviderResource($request->user()->provider), 'Profile retrieved.');
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
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ], fn($v) => $v !== null));
        });

        return $this->success(new ProviderResource($user->fresh()->provider), 'Profile updated successfully.');
    }
}
