<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\FeatureDefinition;
use Illuminate\Database\Seeder;

class FeatureDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $diving = Category::where('slug', 'diving')->first();
        $snorkeling = Category::where('slug', 'snorkeling')->first();

        if ($diving) {
            FeatureDefinition::firstOrCreate(
                ['category_id' => $diving->id, 'key' => 'dive_depth'],
                ['label' => 'Dive Depth (m)', 'type' => 'number', 'is_required' => true, 'sort_order' => 1]
            );
            FeatureDefinition::firstOrCreate(
                ['category_id' => $diving->id, 'key' => 'certification_required'],
                ['label' => 'Certification Required', 'type' => 'boolean', 'is_required' => true, 'sort_order' => 2]
            );
            FeatureDefinition::firstOrCreate(
                ['category_id' => $diving->id, 'key' => 'equipment_included'],
                [
                    'label' => 'Equipment Included',
                    'type' => 'multi_select',
                    'options' => [
                        ['key' => 'bcd', 'label' => 'BCD'],
                        ['key' => 'regulator', 'label' => 'Regulator'],
                        ['key' => 'wetsuit', 'label' => 'Wetsuit'],
                        ['key' => 'tank', 'label' => 'Tank'],
                        ['key' => 'fins', 'label' => 'Fins'],
                    ],
                    'sort_order' => 3,
                ]
            );
            FeatureDefinition::firstOrCreate(
                ['category_id' => $diving->id, 'key' => 'instructor_included'],
                ['label' => 'Instructor Included', 'type' => 'boolean', 'sort_order' => 4]
            );
        }

        if ($snorkeling) {
            FeatureDefinition::firstOrCreate(
                ['category_id' => $snorkeling->id, 'key' => 'equipment_included'],
                ['label' => 'Equipment Included', 'type' => 'boolean', 'sort_order' => 1]
            );
            FeatureDefinition::firstOrCreate(
                ['category_id' => $snorkeling->id, 'key' => 'guide_included'],
                ['label' => 'Guide Included', 'type' => 'boolean', 'sort_order' => 2]
            );
            FeatureDefinition::firstOrCreate(
                ['category_id' => $snorkeling->id, 'key' => 'boat_type'],
                [
                    'label' => 'Boat Type',
                    'type' => 'select',
                    'options' => [
                        ['key' => 'speedboat', 'label' => 'Speedboat'],
                        ['key' => 'sailboat', 'label' => 'Sailboat'],
                        ['key' => 'catamaran', 'label' => 'Catamaran'],
                    ],
                    'sort_order' => 3,
                ]
            );
            FeatureDefinition::firstOrCreate(
                ['category_id' => $snorkeling->id, 'key' => 'duration_hours'],
                ['label' => 'Duration (hours)', 'type' => 'number', 'sort_order' => 4]
            );
        }

        $this->command->info('Feature definitions seeded for Diving and Snorkeling categories.');
    }
}
