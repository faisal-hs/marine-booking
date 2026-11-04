<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Account\SetDefaultAccountRequest;
use App\Http\Requests\Account\SwitchAccountRequest;
use App\Http\Resources\Auth\AuthResource;
use App\Http\Resources\Customer\CustomerResource;
use App\Http\Resources\Provider\ProviderResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;

/**
 * The signed-in person's accounts. Works with either token (customer or provider): it is how the app
 * learns which account it is in, which others it can switch to, and changes the default.
 */
class AccountController extends BaseApiController
{
    public function __construct(protected AccountService $accounts) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return $this->success($this->payload($user, $this->accounts->currentAccount($user)), 'Account retrieved.');
    }

    public function setDefault(SetDefaultAccountRequest $request)
    {
        $user = $this->accounts->setDefault($request->user(), $request->validated('default_account'));

        return $this->success($this->payload($user, $this->accounts->currentAccount($user)), 'Default account updated.');
    }

    public function switch(SwitchAccountRequest $request)
    {
        $target = $request->validated('account_type');
        $session = $this->accounts->switchTo($request->user(), $target, $request->user()->currentAccessToken());

        return $this->success($this->payload($session['user'], $target, $session['token']), "Switched to your {$target} account.");
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    /** The same shape for every account response: the account context, the user, and that account's profile. */
    protected function payload(User $user, string $current, ?string $token = null): array
    {
        $user->unsetRelation('customer')->unsetRelation('provider');

        $profile = $current === AccountService::PROVIDER
            ? ($user->provider ? (new ProviderResource($user->provider))->resolve(request()) : null)
            : ($user->customer ? (new CustomerResource($user->customer))->resolve(request()) : null);

        return array_filter([
            'token' => $token,
            'account' => $this->accounts->context($user, $current),
            'user' => AuthResource::userFields($user),
            'profile' => $profile,
        ], fn($value) => $value !== null);
    }
}
