<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Models\Device;
use Illuminate\Http\Request;

/**
 * Shared across customer/provider/admin route groups — device
 * registration logic is identical regardless of guard (auth:sanctum for
 * customer/provider, auth:web for admin), it only needs $request->user().
 * Registering FCM/APNs push tokens for admins is exactly as valid as for
 * customers/providers: routing works uniformly because devices.user_id
 * points at the shared users table for all three roles alike.
 */
class DeviceController extends BaseApiController
{
    public function store(RegisterDeviceRequest $request)
    {
        $user = $request->user();

        // If this exact token was previously registered to a DIFFERENT
        // user (a real, common scenario: app reinstall, or logout then
        // login as someone else on the same physical device), remove
        // that stale row first — otherwise the old user would keep
        // receiving pushes meant for this device too.
        Device::where('fcm_token', $request->input('fcm_token'))
            ->where('user_id', '!=', $user->id)
            ->delete();

        $user->devices()->updateOrCreate(
            ['fcm_token' => $request->input('fcm_token')],
            ['platform' => $request->input('platform'), 'last_seen_at' => now()]
        );

        return $this->success(null, 'Device registered successfully.');
    }

    public function destroy(Request $request, string $fcmToken)
    {
        $request->user()->devices()->where('fcm_token', $fcmToken)->delete();

        return $this->noContent('Device unregistered successfully.');
    }
}
