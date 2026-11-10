<?php

namespace App\Services\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Favorite;
use App\Models\Reservation;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deleting a customer account. A person with a trip still to come, or with a provider account, cannot do it yet (they are told why). Otherwise the
 * person is removed and what the platform must keep stays without them: the bookings, payments and reviews remain (accounting and the providers'
 * records need them) but the name, phone, email, avatar and the contact details on the bookings are gone; the favourites, devices and conversations
 * are deleted; every sign-in is revoked.
 */
class AccountDeletion
{
    public function delete(Customer $customer): void
    {
        $user = $customer->user;

        if ($user->provider) {
            throw new ProviderApiException('This phone also has a provider account. Close the provider account first.', 409, ErrorCode::ACCOUNT_IS_PROVIDER);
        }

        $active = Reservation::where('customer_id', $customer->id)->where(fn ($q) => $q->where(fn ($p) => $p->where('status', 'pending')->where('hold_expires_at', '>', now()))->orWhere('status', 'confirmed'))->count();
        if ($active > 0) {
            throw new ProviderApiException("You have {$active} booking(s) that are not finished. Cancel them or wait until they are completed first.", 409, ErrorCode::ACCOUNT_HAS_ACTIVE_BOOKINGS);
        }

        DB::transaction(function () use ($customer, $user) {
            Favorite::where('customer_id', $customer->id)->delete();
            Conversation::where('customer_id', $customer->id)->delete();
            Device::where('user_id', $user->id)->delete();
            Reservation::where('customer_id', $customer->id)->update(['contact_name' => null, 'contact_phone' => null, 'contact_email' => null]);

            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $customer->update(['date_of_birth' => null, 'notify_email' => false, 'notify_sms' => false, 'notify_marketing' => false]);
            // The phone is unique, so it is replaced by a value no one can sign in with; the person can sign up again with the same number later.
            $user->forceFill(['name' => __('customer_app.deleted_customer'), 'email' => null, 'phone' => 'deleted:'.$user->uuid, 'avatar_path' => null, 'password' => Hash::make(Str::random(40)), 'status' => 'inactive'])->save();
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
