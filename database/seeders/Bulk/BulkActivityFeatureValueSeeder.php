<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;
use Illuminate\Support\Facades\DB;

/**
 * The attribute values of each activity, for the definitions of ITS OWN category only (the rule the provider API
 * enforces), stored in the column that matches the type: text and select in value_text, numbers in value_number,
 * booleans in value_boolean, multi-selects as a JSON list. Required attributes are always filled, others 80% of the time.
 * Volume at scale 1: about 59,000.
 * Prerequisites: BulkActivitySeeder, and feature definitions (FeatureDefinitionSeeder or BulkFeatureDefinitionSeeder).
 */
class BulkActivityFeatureValueSeeder extends BulkSeeder
{
    use IteratesBulkActivities;

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));
        $this->needs('feature_definitions', 'FeatureDefinitionSeeder or BulkFeatureDefinitionSeeder');

        $definitions = [];
        foreach (DB::table('feature_definitions')->where('status', 'active')->whereNull('deleted_at')->orderBy('sort_order')->get() as $definition) {
            $definitions[$definition->category_id][] = $definition;
        }

        $this->insert('activity_feature_values', (function () use ($definitions) {
            foreach ($this->activitiesWithout('activity_feature_values') as $activity) {
                foreach ($definitions[$activity->category_id] ?? [] as $definition) {
                    if (! $definition->is_required && ! $this->stable("feature-{$activity->id}-{$definition->id}", 80)) {
                        continue;
                    }

                    yield [
                        'uuid' => $this->uuid('feature-value', "{$activity->id}-{$definition->id}"),
                        'activity_id' => $activity->id,
                        'feature_definition_id' => $definition->id,
                    ] + $this->value($definition) + ['created_at' => $activity->created_at, 'updated_at' => $activity->created_at];
                }
            }
        })());
    }

    /** @return array{value_text: ?string, value_number: ?float, value_boolean: ?int, value_json: ?string} */
    private function value(object $definition): array
    {
        $none = ['value_text' => null, 'value_number' => null, 'value_boolean' => null, 'value_json' => null];
        $keys = array_column(json_decode($definition->options ?? '[]', true) ?: [], 'key');

        return match ($definition->type) {
            'number' => ['value_number' => match ($definition->key) {
                'max_depth_m' => $this->rand(5, 40), 'max_group_size' => $this->rand(4, 30), default => $this->rand(1, 50),
            }] + $none,
            'boolean' => ['value_boolean' => $this->chance(60) ? 1 : 0] + $none,
            'select' => ['value_text' => $keys ? $this->pick($keys) : null] + $none,
            'multi_select' => ['value_json' => json_encode($keys ? (array) array_map(fn ($i) => $keys[$i], (array) array_rand($keys, min(count($keys), $this->rand(1, 3)))) : [])] + $none,
            default => ['value_text' => $this->pick(['Crystal-clear water', 'Small groups only', 'Sea turtles often seen', 'Perfect for beginners', 'Includes a hot lunch', 'Spectacular coral gardens'])] + $none,
        };
    }
}
