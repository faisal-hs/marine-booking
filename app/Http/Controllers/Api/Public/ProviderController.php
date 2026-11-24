<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\CustomerApp\ListActivityReviewsRequest;
use App\Http\Requests\CustomerApp\ListProviderActivitiesRequest;
use App\Services\CustomerApp\ProviderPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A provider as anyone can see them, with no token, in the older public API's envelope ({success, message, data} and, for a list, `pagination`): the page, all the provider's reviews and
 * all their activities. The provider is found by id (or uuid, or slug). The same data as /customer-app/providers/*, from the same ProviderPage.
 */
class ProviderController extends BaseApiController
{
    public function __construct(private readonly ProviderPage $providers) {}

    public function show(Request $request, string $provider)
    {
        $model = $this->providers->find($provider, anyKey: true);

        return $model ? $this->success($this->providers->profile($model), 'Provider retrieved.') : $this->error('Provider not found.', 404);
    }

    public function reviews(ListActivityReviewsRequest $request, string $provider)
    {
        $model = $this->providers->find($provider, anyKey: true);

        if (! $model) {
            return $this->error('Provider not found.', 404);
        }

        $page = $this->providers->reviews($model)->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')))
            ->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));

        return $this->paged($page, collect($page->items())->map(fn ($r) => $this->providers->review($r))->all(), 'Provider reviews retrieved.');
    }

    public function activities(ListProviderActivitiesRequest $request, string $provider)
    {
        $model = $this->providers->find($provider, anyKey: true);

        if (! $model) {
            return $this->error('Provider not found.', 404);
        }

        $page = $this->providers->activitiesQuery($model, $request->only(['category_id', 'sort']))->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));

        return $this->paged($page, collect($page->items())->map(fn ($a) => $this->providers->activity($a))->all(), 'Provider activities retrieved.');
    }

    /** The older API's paginated envelope, for rows that are already arrays. @param  list<array<string, mixed>>  $rows */
    private function paged(LengthAwarePaginator $page, array $rows, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $rows,
            'pagination' => ['current_page' => $page->currentPage(), 'total_pages' => $page->lastPage(), 'per_page' => $page->perPage(), 'total_items' => $page->total()],
        ]);
    }
}
