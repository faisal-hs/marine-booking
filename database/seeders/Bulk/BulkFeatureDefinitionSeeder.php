<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;

/**
 * The extra attributes each bulk category defines (difficulty, depth, languages ...).
 * Volume at scale 1: 4 to 6 per bulk category, about 200.
 * Prerequisites: BulkCategorySeeder.
 */
class BulkFeatureDefinitionSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('categories', 'BulkCategorySeeder', fn ($q) => $q->where('sort_order', '>=', 100));

        $already = array_flip(DB::table('feature_definitions')->distinct()->pluck('category_id')->all());
        $templates = $this->featureTemplates();
        $stamp = $this->ts($this->now);
        $categories = DB::table('categories')->where('sort_order', '>=', 100)->orderBy('id')->pluck('id');

        $this->insert('feature_definitions', (function () use ($categories, $already, $templates, $stamp) {
            foreach ($categories as $categoryId) {
                if (isset($already[$categoryId])) {
                    continue;
                }

                $chosen = $this->sampleKeys($templates, $this->rand(4, 6));
                sort($chosen);

                foreach ($chosen as $order => $index) {
                    [$key, $label, $type, $options] = $templates[$index];

                    yield [
                        'uuid' => $this->uuid('feature', "{$categoryId}-{$key}"),
                        'category_id' => $categoryId,
                        'key' => $key,
                        'label' => $label,
                        'type' => $type,
                        'options' => $options ? json_encode($options) : null,
                        'is_required' => $order === 0 && $this->chance(25) ? 1 : 0,
                        'sort_order' => $order,
                        'status' => 'active',
                        'deleted_at' => null,
                        'created_at' => $stamp,
                        'updated_at' => $stamp,
                    ];
                }
            }
        })());
    }
}
