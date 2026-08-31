<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ActivityInclusionService
{
    public function sync(Activity $activity, array $items): Collection
    {
        return DB::transaction(function () use ($activity, $items) {
            $activity->inclusions()->delete();

            $created = collect();

            foreach (array_values($items) as $index => $item) {
                $created->push($activity->inclusions()->create([
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'sort_order' => $index,
                ]));
            }

            return $created;
        });
    }
}
