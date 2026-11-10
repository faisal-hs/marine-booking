<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Models\Category;
use App\Models\City;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\CustomerApp\CustomerNotifications;
use Illuminate\Http\Request;

/** Everything the home screen shows, in one request. */
class HomeController extends CustomerAppController
{
    public function __construct(private readonly ActivityCatalog $catalog, private readonly CustomerNotifications $notifications) {}

    public function show(Request $request)
    {
        $viewer = $this->viewer($request);
        $limit = (int) config('customer_app.home_limit');
        $with = ['city', 'coverImage', 'provider:id,city'];

        $featured = $this->catalog->visible()->with($with)->where('activities.is_featured', true)->orderByDesc('activities.average_rating')->orderBy('activities.id')->limit($limit)->get();
        $popular = $this->catalog->visible()->with($with)->orderByRaw(ActivityCatalog::BOOKED_SQL.' DESC')->orderByDesc('activities.average_rating')->orderBy('activities.id')->limit($limit)->get();
        $favorites = $this->catalog->favoritesAmong($viewer?->id, $featured->concat($popular));

        return $this->ok([
            'unread_notifications' => $viewer ? $this->notifications->unreadCount($viewer->user) : 0,
            'categories' => Category::where('status', 'active')->orderBy('sort_order')->orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'icon' => $this->catalog->url($c->image)])->all(),
            'featured' => $featured->map(fn ($a) => $this->catalog->card($a, $favorites))->all(),
            'destinations' => $this->destinations(),
            'popular' => $popular->map(fn ($a) => $this->catalog->card($a, $favorites))->all(),
        ]);
    }

    /** @return list<array{id: int, name: string, image: ?string, activities_count: int}> the active cities that have at least one activity to book */
    private function destinations(): array
    {
        $counts = $this->catalog->visible()->reorder()->whereNotNull('activities.city_id')->groupBy('activities.city_id')
            ->selectRaw('activities.city_id AS cid, COUNT(*) AS total')->toBase()->get()->pluck('total', 'cid');

        return City::where('is_active', true)->whereIn('id', $counts->keys())->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (City $c) => ['id' => $c->id, 'name' => $c->name(), 'image' => $this->catalog->url($c->image), 'activities_count' => (int) $counts[$c->id]])->all();
    }
}
