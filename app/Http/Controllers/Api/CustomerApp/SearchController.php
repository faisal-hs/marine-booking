<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Models\Category;
use App\Models\City;
use App\Services\CustomerApp\ActivityCatalog;

/** The search screen's helpers: the terms shown under the box, and the filters' options. */
class SearchController extends CustomerAppController
{
    public function __construct(private readonly ActivityCatalog $catalog) {}

    /** A short list chosen by the platform (config of the content in lang/customer_app.php), not drawn from what customers type. */
    public function popular()
    {
        return $this->ok(array_values((array) __('customer_app.popular_searches')));
    }

    public function filters()
    {
        $range = $this->catalog->visible()->reorder()->toBase()->get(['min_price'])->pluck('min_price');

        $cityIds = $this->catalog->visible()->reorder()->whereNotNull('activities.city_id')->distinct()->toBase()->pluck('activities.city_id');
        $categoryIds = $this->catalog->visible()->reorder()->distinct()->toBase()->pluck('activities.category_id');

        return $this->ok([
            'cities' => City::where('is_active', true)->whereIn('id', $cityIds)->orderBy('sort_order')->orderBy('id')->get()->map(fn (City $c) => ['id' => $c->id, 'name' => $c->name()])->all(),
            'categories' => Category::where('status', 'active')->whereIn('id', $categoryIds)->orderBy('sort_order')->orderBy('id')->get()->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'min_price' => $range->isEmpty() ? 0 : (float) $range->min(),
            'max_price' => $range->isEmpty() ? 0 : (float) $range->max(),
            'sorts' => ActivityCatalog::SORTS,
        ]);
    }
}
