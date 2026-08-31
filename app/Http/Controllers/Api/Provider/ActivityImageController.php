<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\ActivityImage\ReorderActivityImagesRequest;
use App\Http\Requests\Provider\ActivityImage\StoreActivityImagesRequest;
use App\Http\Resources\ActivityImage\ActivityImageResource;
use App\Models\Activity;
use App\Models\ActivityImage;
use App\Services\ActivityImageService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ActivityImageController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ActivityImageService $service) {}

    public function store(StoreActivityImagesRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $images = $this->service->upload($activity, $request->file('images'));

        return $this->created(ActivityImageResource::collection($images), 'Images uploaded successfully.');
    }

    public function destroy(Activity $activity, ActivityImage $image)
    {
        $this->authorize('update', $activity);
        $this->abortIfImageDoesNotBelongToActivity($activity, $image);

        $this->service->delete($image);

        return $this->noContent('Image deleted successfully.');
    }

    public function setCover(Activity $activity, ActivityImage $image)
    {
        $this->authorize('update', $activity);
        $this->abortIfImageDoesNotBelongToActivity($activity, $image);

        $this->service->setCover($activity, $image);

        return $this->success(null, 'Cover image updated successfully.');
    }

    public function reorder(ReorderActivityImagesRequest $request, Activity $activity)
    {
        $this->authorize('update', $activity);

        $this->service->reorder($activity, $request->input('order'));

        return $this->success(
            ActivityImageResource::collection($activity->fresh()->images),
            'Images reordered successfully.'
        );
    }

    /**
     * Route-model binding resolves $image globally by uuid, not scoped to
     * $activity — without this check, a provider could pass another
     * activity's image uuid alongside their own activity's uuid and act
     * on an image they don't actually own.
     */
    protected function abortIfImageDoesNotBelongToActivity(Activity $activity, ActivityImage $image): void
    {
        abort_if($image->activity_id !== $activity->id, 404);
    }
}
