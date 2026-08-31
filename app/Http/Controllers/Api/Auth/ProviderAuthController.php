<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Auth\Provider\RequestOtpRequest;
use App\Http\Requests\Auth\Provider\ResendOtpRequest;
use App\Http\Requests\Auth\Provider\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Services\Auth\ProviderAuthService;
use Illuminate\Http\Request;

class ProviderAuthController extends BaseApiController
{
    public function __construct(protected ProviderAuthService $authService) {}

    public function requestOtp(RequestOtpRequest $request)
    {
        $data = $request->payload();

        $this->authService->requestOtp($data['phone'], $data['owner_name'] ?? null, $data['business_name'] ?? null);

        return $this->success(null, 'Verification code sent.');
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        $data = $request->payload();

        $result = $this->authService->verifyOtp($data['phone'], $data['code']);

        return $this->success(new AuthResource($result), 'Verified successfully.');
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        $this->authService->resendOtp($request->payload()['phone']);

        return $this->success(null, 'Verification code resent.');
    }

    public function logout(Request $request)
    {
        $this->authService->logout($request->user());

        return $this->noContent('Logged out successfully.');
    }
}
