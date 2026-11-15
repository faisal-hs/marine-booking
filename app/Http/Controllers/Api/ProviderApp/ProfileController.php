<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\PatchProfileRequest;
use App\Http\Resources\Provider\ProviderResource;
use App\Services\ProviderProfileService;
use Illuminate\Validation\ValidationException;

/**
 * PATCH /provider/profile: partial update. The older PUT /provider/profile stays for existing clients and follows the same rule: ordinary
 * information changes at once; critical information (config/provider_app.php) waits for an admin, and the response says so.
 */
class ProfileController extends ProviderAppController
{
    public function __construct(private readonly ProviderProfileService $profiles) {}

    public function update(PatchProfileRequest $request)
    {
        $changes = $request->changes();

        if ($changes === [] && ! $request->changesImage()) {
            throw ValidationException::withMessages(['body' => ['Send at least one field to change.']]);
        }

        $user = $request->user();
        $result = $this->profiles->update($user, $changes);
        // The picture is not critical information: it changes at once, whatever else waits for an admin.
        $this->profiles->changeImage($result['provider'], $request->file('image'), $request->boolean('remove_image'));

        return $this->ok(
            (new ProviderResource($result['provider']))->resolve($request),
            $result['waiting'] === []
                ? 'Profile updated'
                : 'Profile updated; your change to '.implode(', ', str_replace('_', ' ', $result['waiting'])).' is waiting for admin review'
        );
    }
}
