<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Provider\RejectProfileChangeRequest;
use App\Models\Provider;
use App\Models\ProviderProfileChange;
use App\Services\ProviderProfileService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** An admin approves or rejects a provider's change to critical profile information. Whoever may update a provider may decide it. */
class ProviderProfileChangeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ProviderProfileService $profiles) {}

    public function approve(Provider $provider, ProviderProfileChange $change): RedirectResponse
    {
        $this->authorize('update', $provider);
        abort_unless($change->provider_id === $provider->id, 404);

        try {
            $this->profiles->approve($change, request()->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.providers.profile_change.approved'));
    }

    public function reject(RejectProfileChangeRequest $request, Provider $provider, ProviderProfileChange $change): RedirectResponse
    {
        $this->authorize('update', $provider);
        abort_unless($change->provider_id === $provider->id, 404);

        try {
            $this->profiles->reject($change, $request->user(), $request->input('rejection_reason'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.providers.profile_change.rejected'));
    }
}
