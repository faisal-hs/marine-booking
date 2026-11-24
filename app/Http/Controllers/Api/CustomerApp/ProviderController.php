<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\ListActivityReviewsRequest;
use App\Http\Requests\CustomerApp\ListProviderActivitiesRequest;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\CustomerApp\ProviderPage;
use Illuminate\Http\Request;

/** A provider as customers see them, open to everyone (a signed-in customer's token only makes the activities' `is_favorite` personal). See ProviderPage. */
class ProviderController extends CustomerAppController
{
    public function __construct(private readonly ProviderPage $providers, private readonly ActivityCatalog $catalog) {}

    public function show(Request $request, string $id)
    {
        $provider = $this->providers->find($id) ?? throw ProviderApiException::notFound('Provider not found.');

        return $this->ok($this->providers->profile($provider, $this->viewer($request)), 'Provider retrieved.');
    }

    public function reviews(ListActivityReviewsRequest $request, string $id)
    {
        $provider = $this->providers->find($id) ?? throw ProviderApiException::notFound('Provider not found.');

        $page = $this->providers->reviews($provider)->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')))
            ->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));

        return $this->page($page, collect($page->items())->map(fn ($r) => $this->providers->review($r))->all());
    }

    public function activities(ListProviderActivitiesRequest $request, string $id)
    {
        $provider = $this->providers->find($id) ?? throw ProviderApiException::notFound('Provider not found.');

        $page = $this->providers->activitiesQuery($provider, $request->only(['category_id', 'sort']))->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));
        $favorites = $this->catalog->favoritesAmong($this->viewer($request)?->id, $page->items());

        return $this->page($page, collect($page->items())->map(fn ($a) => $this->providers->activity($a, $favorites))->all());
    }
}
