<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\Favorite\FavoriteResource;
use App\Models\Favorite;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\FavoriteRepositoryInterface;
use Illuminate\Http\Request;

class FavoriteController extends BaseApiController
{
    public function __construct(
        protected FavoriteRepositoryInterface $repository,
        protected ActivityRepositoryInterface $activityRepository,
    ) {}

    public function index(PaginationRequest $request)
    {
        $customer = $request->user()->customer;

        $favorites = $this->repository->forCustomer($customer->id, $request->perPage());

        return $this->success(FavoriteResource::collection($favorites));
    }

    public function store(Request $request)
    {
        $request->validate(['activity' => ['required', 'string']]);

        $customer = $request->user()->customer;

        $activity = $this->activityRepository->findByIdOrUuidOrSlug($request->input('activity'));

        if (! $activity || $activity->status !== 'published') {
            return $this->error('Activity not found.', 404);
        }

        if ($this->repository->exists($customer->id, $activity->id)) {
            return $this->error('This activity is already in your favorites.', 422);
        }

        $favorite = $this->repository->create([
            'customer_id' => $customer->id,
            'activity_id' => $activity->id,
        ]);

        $favorite->load('activity.category');

        return $this->created(new FavoriteResource($favorite), 'Added to favorites.');
    }

    public function destroy(Request $request, string $activity)
    {
        $customer = $request->user()->customer;

        $activityModel = $this->activityRepository->findByIdOrUuidOrSlug($activity);

        if (! $activityModel) {
            return $this->error('Activity not found.', 404);
        }

        $favorite = Favorite::where('customer_id', $customer->id)
            ->where('activity_id', $activityModel->id)
            ->first();

        if (! $favorite) {
            return $this->error('This activity is not in your favorites.', 404);
        }

        $this->repository->delete($favorite);

        return $this->noContent('Removed from favorites.');
    }
}
