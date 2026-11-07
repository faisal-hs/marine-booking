<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\UpdateProfileRequest;
use App\Http\Resources\Provider\ProviderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends BaseApiController
{
    public function __construct(private readonly \App\Services\ProviderProfileService $profiles) {}

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
        $user = $request->user();

        // As before, a field sent as null is left alone (PATCH is the way to clear one). Critical information (the business name) now waits for
        // an admin, the same as through PATCH: the rule is about the information, not the verb.
        $result = $this->profiles->update($user, array_filter($request->payload(), fn ($v) => $v !== null));

        return $this->success(
            $this->withAccount($user, new ProviderResource($user->fresh()->provider)),
            $result['waiting'] === []
                ? 'Profile updated successfully.'
                : 'Profile updated. Your change to '.implode(', ', str_replace('_', ' ', $result['waiting'])).' is waiting for admin review and takes effect once approved.'
        );
    }
}
