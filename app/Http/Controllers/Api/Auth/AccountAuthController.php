<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Account\RequestOtpRequest;
use App\Http\Requests\Account\ResendOtpRequest;
use App\Http\Requests\Account\VerifyOtpRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Services\Auth\AccountAuthService;

/** Sign-in for someone who already has an account, into whichever one they chose as their default. */
class AccountAuthController extends BaseApiController
{
    public function __construct(protected AccountAuthService $authService) {}

    public function requestOtp(RequestOtpRequest $request)
    {
        $this->authService->requestOtp($request->payload()['phone']);

        return $this->success(null, 'Verification code sent.');
    }

    public function verifyOtp(VerifyOtpRequest $request)
    {
        $data = $request->payload();

        return $this->success(new AuthResource($this->authService->verifyOtp($data['phone'], $data['code'])), 'Verified successfully.');
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        $this->authService->resendOtp($request->payload()['phone']);

        return $this->success(null, 'Verification code resent.');
    }
}
