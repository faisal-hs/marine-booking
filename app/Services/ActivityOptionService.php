<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ActivityOptionService
{
    /**
     * Full replace: the provider submits the complete desired list, and
     * this deletes existing options and recreates them, preserving the
     * submitted order as sort_order. Deliberately not a per-item CRUD API,
     * since options have no independent lifecycle beyond the activity.
     */
    public function sync(Activity $activity, array $items): Collection
    {
        return DB::transaction(function () use ($activity, $items) {
            $activity->options()->delete();

            $created = collect();

            foreach (array_values($items) as $index => $item) {
                $created->push($activity->options()->create([
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'extra_price' => $item['extra_price'] ?? 0,
                    'is_required' => $item['is_required'] ?? false,
                    'max_quantity' => $item['max_quantity'] ?? null,
                    'sort_order' => $index,
                ]));
            }

            return $created;
        });
    }
}
