<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ActivityRequirementService
{
    public function sync(Activity $activity, array $items): Collection
    {
        return DB::transaction(function () use ($activity, $items) {
            $activity->requirements()->delete();

            $created = collect();

            foreach (array_values($items) as $index => $item) {
                $created->push($activity->requirements()->create([
                    'text' => $item['text'],
                    'is_mandatory' => $item['is_mandatory'] ?? true,
                    'sort_order' => $index,
                ]));
            }

            return $created;
        });
    }
}
