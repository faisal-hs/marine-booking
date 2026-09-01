<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Activity\RejectActivityRequest;
use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Services\ActivityModerationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActivityController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ActivityModerationService $service,
        protected ActivityRepositoryInterface $repository,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Activity::class);

        $activities = $this->repository->paginateForAdmin($request->only(['status', 'search']));

        return view('admin.activities.index', [
            'activities' => $activities,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    public function show(Activity $activity): View
    {
        $this->authorize('viewAsAdmin', Activity::class);

        $activity->load(['category', 'provider', 'schedulePeriods', 'availabilityOverrides']);

        return view('admin.activities.show', ['activity' => $activity]);
    }

    public function approve(Activity $activity): RedirectResponse
    {
        $this->authorize('approve', $activity);

        try {
            $this->service->approve($activity);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.activities.approved'));
    }

    public function reject(RejectActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('reject', $activity);

        try {
            $this->service->reject($activity, $request->input('rejection_reason'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.activities.rejected'));
    }

    public function publish(Activity $activity): RedirectResponse
    {
        $this->authorize('publish', $activity);

        try {
            $this->service->publish($activity);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.activities.published'));
    }

    public function deactivate(Activity $activity): RedirectResponse
    {
        $this->authorize('publish', $activity); // same permission governs both directions of the toggle

        try {
            $this->service->deactivate($activity);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.activities.deactivated'));
    }
}
