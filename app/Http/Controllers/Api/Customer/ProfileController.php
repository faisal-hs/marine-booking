<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\UpdateProfileRequest;
use App\Http\Resources\Customer\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends BaseApiController
{
    /** The profile plus which account this is, the default one and which can be switched to. */
    private function withAccount(\App\Models\User $user, CustomerResource $profile): array
    {
        return $profile->resolve(request()) + ['account' => app(\App\Services\AccountService::class)->context($user, 'customer')];
    }

    public function show(Request $request)
    {
        return $this->success($this->withAccount($request->user(), new CustomerResource($request->user()->customer)), 'Profile retrieved.');
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

            $user->customer->update(array_filter([
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? null,
            ], fn($v) => $v !== null));
        });

        return $this->success($this->withAccount($user, new CustomerResource($user->fresh()->customer)), 'Profile updated successfully.');
    }
}
