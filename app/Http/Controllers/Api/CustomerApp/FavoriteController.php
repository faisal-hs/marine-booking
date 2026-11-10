<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\PageRequest;
use App\Models\Favorite;
use App\Services\CustomerApp\ActivityCatalog;
use Illuminate\Http\Request;

/**
 * The customer's saved activities. Only an activity a customer can see can be saved; one that is no longer visible drops out of the list (it stays saved,
 * and comes back if it does). Saving and removing are safe to repeat.
 */
class FavoriteController extends CustomerAppController
{
    public function __construct(private readonly ActivityCatalog $catalog) {}

    public function index(PageRequest $request)
    {
        $customer = $this->customer($request);

        $page = $this->catalog->visible()->with(['city', 'coverImage', 'provider:id,city'])
            ->join('favorites', 'favorites.activity_id', '=', 'activities.id')->where('favorites.customer_id', $customer->id)
            ->reorder()->orderByDesc('favorites.created_at')->orderByDesc('favorites.id')
            ->paginate($request->perPage(), ['activities.*'], 'page', $request->integer('page', 1));

        return $this->page($page, collect($page->items())->map(fn ($a) => $this->catalog->card($a, [$a->id]))->all());
    }

    public function store(Request $request, string $activityId)
    {
        $activity = $this->catalog->find($activityId) ?? throw ProviderApiException::notFound('Activity not found.');

        Favorite::firstOrCreate(['customer_id' => $this->customer($request)->id, 'activity_id' => $activity->id]);

        return $this->ok(['is_favorite' => true], 'Added to saved');
    }

    public function destroy(Request $request, string $activityId)
    {
        if (ctype_digit($activityId)) {
            Favorite::where('customer_id', $this->customer($request)->id)->where('activity_id', (int) $activityId)->delete();
        }

        return $this->ok(['is_favorite' => false], 'Removed from saved');
    }
}
