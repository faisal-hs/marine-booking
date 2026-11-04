<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Account\SubmitProviderRequestRequest;
use App\Http\Resources\Account\ProviderRequestStatusResource;
use App\Services\ProviderRequestService;
use Illuminate\Http\Request;

/**
 * Asking to become a provider. Open to a customer token (a customer who wants to start offering
 * activities) and to a provider token that is not approved yet (someone who just registered as a
 * provider): it is the one thing an unapproved provider can do.
 */
class ProviderRequestController extends BaseApiController
{
    public function __construct(protected ProviderRequestService $requests) {}

    public function show(Request $request)
    {
        return $this->success(new ProviderRequestStatusResource($request->user()), 'Provider request status retrieved.');
    }

    public function store(SubmitProviderRequestRequest $request)
    {
        $this->requests->submit($request->user(), $request->details(), $request->documents());

        return $this->success(
            new ProviderRequestStatusResource($request->user()->fresh()),
            'Your provider request was submitted. An admin will review it and you will be notified.',
            201
        );
    }
}
