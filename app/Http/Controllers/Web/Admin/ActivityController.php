<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Activity\SuspendActivityRequest;
use App\Http\Requests\Web\Admin\Activity\StoreActivityRequest;
use App\Http\Requests\Web\Admin\Activity\StoreImagesRequest;
use App\Http\Requests\Web\Admin\Activity\SyncFeaturesRequest;
use App\Http\Requests\Web\Admin\Activity\SyncInclusionsRequest;
use App\Http\Requests\Web\Admin\Activity\SyncRequirementsRequest;
use App\Http\Requests\Web\Admin\Activity\UpdateActivityRequest;
use App\Models\Activity;
use App\Models\ActivityImage;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\FeatureDefinitionRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Services\ActivityFeatureService;
use App\Services\ActivityImageService;
use App\Services\ActivityInclusionService;
use App\Services\ActivityPublishingService;
use App\Services\ActivityRequirementService;
use App\Services\ActivityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActivityController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ActivityPublishingService $publishing,
        protected ActivityRepositoryInterface $repository,
        protected ActivityService $activities,
        protected ActivityFeatureService $features,
        protected ActivityRequirementService $requirements,
        protected ActivityInclusionService $inclusions,
        protected ActivityImageService $images,
        protected FeatureDefinitionRepositoryInterface $definitions,
        protected CategoryRepositoryInterface $categories,
        protected ProviderRepositoryInterface $providers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Activity::class);

        $filters = $request->only(['status', 'search', 'trashed']);

        return view('admin.activities.index', [
            'activities' => $this->repository->paginateForAdmin($filters),
            'filters' => $filters,
        ]);
    }

    public function show(Activity $activity): View
    {
        $this->authorize('viewAsAdmin', Activity::class);

        $activity->load(['category', 'provider', 'schedulePeriods', 'availabilityOverrides', 'bookingOptions.pricingRules.schedulePeriod']);

        return view('admin.activities.show', ['activity' => $activity]);
    }

    /** Stops an activity (the provider cannot switch it back on: only lift() can). The admin approves providers, never activities. */
    public function suspend(SuspendActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('suspend', $activity);

        try {
            $this->publishing->suspend($activity, $request->validated('suspension_reason'), $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.activities.suspended'));
    }

    public function lift(Activity $activity): RedirectResponse
    {
        $this->authorize('suspend', $activity);   // the same permission governs both directions

        try {
            $lifted = $this->publishing->lift($activity);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __($lifted->status === 'published' ? 'admin.activities.lifted_live' : 'admin.activities.lifted_hidden'));
    }

    // --- Staff create / edit / delete ------------------------------------------------
    //
    // Staff edit an activity like its provider does: the change applies at once.

    public function create(): View
    {
        $this->authorize('adminCreate', Activity::class);

        return view('admin.activities.create', [
            'providers' => $this->providers->forSelect(),
            'categories' => $this->categories->activeOrdered(),
        ]);
    }

    public function store(StoreActivityRequest $request): RedirectResponse
    {
        $activity = $this->activities->create($request->provider(), $request->payload());

        return redirect()->route('admin.activities.edit', $activity)->with('status', __('admin.activities.created'));
    }

    public function edit(Activity $activity): View
    {
        $this->authorize('adminUpdate', $activity);

        $activity->load(['provider', 'images', 'requirements', 'inclusions']);

        return view('admin.activities.edit', [
            'activity' => $activity,
            'categories' => $this->categories->activeOrdered(),
            'definitions' => $this->definitions->forCategory($activity->category_id),
            'featureValues' => $activity->featureValues()->with('featureDefinition')->get()
                ->mapWithKeys(fn ($value) => [$value->featureDefinition->key => $value->value])->all(),
        ]);
    }

    public function update(UpdateActivityRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);

        $this->activities->update($activity, $request->payload());

        return redirect()->route('admin.activities.edit', $activity)->with('status', __('admin.activities.updated'));
    }

    public function syncFeatures(SyncFeaturesRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);

        $this->features->sync($activity, $request->payload());

        return $this->backToEdit($activity, 'features', 'features_saved');
    }

    public function syncRequirements(SyncRequirementsRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);

        $this->requirements->sync($activity, $request->payload());

        return $this->backToEdit($activity, 'requirements', 'requirements_saved');
    }

    public function syncInclusions(SyncInclusionsRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);

        $this->inclusions->sync($activity, $request->payload());

        return $this->backToEdit($activity, 'inclusions', 'inclusions_saved');
    }

    public function storeImages(StoreImagesRequest $request, Activity $activity): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);

        $this->images->upload($activity, $request->file('images'));

        return $this->backToEdit($activity, 'photos', 'photos_saved');
    }

    public function destroyImage(Activity $activity, ActivityImage $image): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);
        abort_if($image->activity_id !== $activity->id, 404);

        $this->images->delete($image);

        return $this->backToEdit($activity, 'photos', 'photo_deleted');
    }

    public function setCover(Activity $activity, ActivityImage $image): RedirectResponse
    {
        $this->authorize('adminUpdate', $activity);
        abort_if($image->activity_id !== $activity->id, 404);

        $this->images->setCover($activity, $image);

        return $this->backToEdit($activity, 'photos', 'cover_saved');
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        $this->authorize('adminDelete', $activity);

        try {
            $this->activities->delete($activity);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.activities.index')->with('status', __('admin.activities.deleted'));
    }

    public function restore(Activity $activity): RedirectResponse
    {
        $this->authorize('adminRestore', $activity);

        $this->activities->restore($activity);

        return redirect()->route('admin.activities.show', $activity)->with('status', __('admin.activities.restored'));
    }

    /** Back to the section of the edit page the change was made in. */
    protected function backToEdit(Activity $activity, string $section, string $messageKey): RedirectResponse
    {
        return redirect(route('admin.activities.edit', $activity).'#'.$section)->with('status', __('admin.activities.'.$messageKey));
    }
}
