<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Http\Requests\CustomerApp\DeleteAccountRequest;
use App\Http\Requests\CustomerApp\UpdateProfileRequest;
use App\Services\CustomerApp\AccountDeletion;
use App\Services\CustomerApp\CustomerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The signed-in customer's own profile, signing out, and deleting the account. */
class ProfileController extends CustomerAppController
{
    public function __construct(private readonly CustomerProfile $profile, private readonly AccountDeletion $deletion) {}

    public function show(Request $request)
    {
        return $this->ok($this->profile->present($this->customer($request)));
    }

    /** Only what is sent is changed. A new email has to be verified again. */
    public function update(UpdateProfileRequest $request)
    {
        $customer = $this->customer($request);
        $user = $request->user();

        if ($request->has('name')) {
            $user->name = $request->input('name');
        }

        if ($request->has('email') && $request->input('email') !== $user->email) {
            $user->email = $request->input('email');
            $user->email_verified_at = null;
        }

        if ($file = $request->file('avatar')) {
            $old = $user->avatar_path;
            $user->avatar_path = $file->storeAs('avatars', Str::uuid().'.'.$file->extension(), 'public');
            $old && Storage::disk('public')->delete($old);
        }

        $user->save();

        $customer->fill(collect(['email', 'sms', 'marketing'])->filter(fn ($k) => $request->has("notifications.{$k}"))->mapWithKeys(fn ($k) => ["notify_{$k}" => $request->boolean("notifications.{$k}")])->all())->save();

        return $this->ok($this->profile->present($customer->refresh()->setRelation('user', $user)), 'Profile updated');
    }

    public function destroy(DeleteAccountRequest $request)
    {
        $this->deletion->delete($this->customer($request));

        return $this->ok(null, 'Account deleted');
    }

    /** Signs out this device only: the token that made the request. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, 'Logged out successfully');
    }
}
