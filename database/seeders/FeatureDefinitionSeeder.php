<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\FeatureDefinition;
use Illuminate\Database\Seeder;

class FeatureDefinitionSeeder extends Seeder
{
    /**
     * Every category gets 4-5 feature definitions appropriate to that
     * activity type, using only the types the EAV system already
     * supports (text, number, boolean, select, multi_select). Every
     * 'select'/'multi_select' definition includes valid options, matching
     * ActivityFeatureService::buildValidationRules()'s expectations.
     */
    public function run(): void
    {
        $definitions = [
            'diving' => [
                ['key' => 'dive_depth', 'label' => 'Dive Depth (m)', 'type' => 'number', 'is_required' => true],
                ['key' => 'certification_required', 'label' => 'Certification Required', 'type' => 'boolean', 'is_required' => true],
                ['key' => 'equipment_included', 'label' => 'Equipment Included', 'type' => 'multi_select', 'options' => [
                    ['key' => 'bcd', 'label' => 'BCD'],
                    ['key' => 'regulator', 'label' => 'Regulator'],
                    ['key' => 'wetsuit', 'label' => 'Wetsuit'],
                    ['key' => 'tank', 'label' => 'Tank'],
                    ['key' => 'fins', 'label' => 'Fins'],
                ]],
                ['key' => 'instructor_included', 'label' => 'Instructor Included', 'type' => 'boolean'],
                ['key' => 'difficulty', 'label' => 'Difficulty', 'type' => 'select', 'options' => [
                    ['key' => 'beginner', 'label' => 'Beginner'],
                    ['key' => 'intermediate', 'label' => 'Intermediate'],
                    ['key' => 'advanced', 'label' => 'Advanced'],
                ]],
            ],
            'snorkeling' => [
                ['key' => 'equipment_included', 'label' => 'Equipment Included', 'type' => 'boolean'],
                ['key' => 'guide_included', 'label' => 'Guide Included', 'type' => 'boolean'],
                ['key' => 'boat_type', 'label' => 'Boat Type', 'type' => 'select', 'options' => [
                    ['key' => 'speedboat', 'label' => 'Speedboat'],
                    ['key' => 'sailboat', 'label' => 'Sailboat'],
                    ['key' => 'catamaran', 'label' => 'Catamaran'],
                ]],
                ['key' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'number'],
                ['key' => 'marine_life', 'label' => 'Marine Life Highlights', 'type' => 'text'],
            ],
            'fishing' => [
                ['key' => 'fishing_type', 'label' => 'Fishing Type', 'type' => 'select', 'options' => [
                    ['key' => 'deep_sea', 'label' => 'Deep Sea'],
                    ['key' => 'reef', 'label' => 'Reef'],
                    ['key' => 'fly_fishing', 'label' => 'Fly Fishing'],
                ], 'is_required' => true],
                ['key' => 'equipment_included', 'label' => 'Equipment Included', 'type' => 'boolean'],
                ['key' => 'boat_type', 'label' => 'Boat Type', 'type' => 'select', 'options' => [
                    ['key' => 'speedboat', 'label' => 'Speedboat'],
                    ['key' => 'yacht', 'label' => 'Yacht'],
                    ['key' => 'traditional', 'label' => 'Traditional Boat'],
                ]],
                ['key' => 'experience_required', 'label' => 'Experience Required', 'type' => 'boolean'],
                ['key' => 'maximum_fishermen', 'label' => 'Maximum Fishermen', 'type' => 'number'],
            ],
            'boat-trips' => [
                ['key' => 'boat_type', 'label' => 'Boat Type', 'type' => 'select', 'options' => [
                    ['key' => 'speedboat', 'label' => 'Speedboat'],
                    ['key' => 'yacht', 'label' => 'Yacht'],
                    ['key' => 'catamaran', 'label' => 'Catamaran'],
                ]],
                ['key' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'number'],
                ['key' => 'refreshments_included', 'label' => 'Refreshments Included', 'type' => 'boolean'],
                ['key' => 'max_capacity', 'label' => 'Max Capacity', 'type' => 'number'],
            ],
            'yacht-trips' => [
                ['key' => 'yacht_size_ft', 'label' => 'Yacht Size (ft)', 'type' => 'number'],
                ['key' => 'crew_included', 'label' => 'Crew Included', 'type' => 'boolean'],
                ['key' => 'catering_included', 'label' => 'Catering Included', 'type' => 'boolean'],
                ['key' => 'luxury_tier', 'label' => 'Luxury Tier', 'type' => 'select', 'options' => [
                    ['key' => 'standard', 'label' => 'Standard'],
                    ['key' => 'premium', 'label' => 'Premium'],
                    ['key' => 'vip', 'label' => 'VIP'],
                ]],
            ],
            'parasailing' => [
                ['key' => 'max_height_m', 'label' => 'Max Height (m)', 'type' => 'number'],
                ['key' => 'tandem_available', 'label' => 'Tandem Available', 'type' => 'boolean'],
                ['key' => 'min_weight_kg', 'label' => 'Minimum Weight (kg)', 'type' => 'number'],
                ['key' => 'photo_package_available', 'label' => 'Photo Package Available', 'type' => 'boolean'],
            ],
            'jet-ski' => [
                ['key' => 'engine_power_cc', 'label' => 'Engine Power (cc)', 'type' => 'number'],
                ['key' => 'license_required', 'label' => 'License Required', 'type' => 'boolean'],
                ['key' => 'guided_tour', 'label' => 'Guided Tour', 'type' => 'boolean'],
                ['key' => 'max_speed_kmh', 'label' => 'Max Speed (km/h)', 'type' => 'number'],
            ],
            'kayaking' => [
                ['key' => 'kayak_type', 'label' => 'Kayak Type', 'type' => 'select', 'options' => [
                    ['key' => 'single', 'label' => 'Single'],
                    ['key' => 'double', 'label' => 'Double'],
                    ['key' => 'inflatable', 'label' => 'Inflatable'],
                ]],
                ['key' => 'guided', 'label' => 'Guided', 'type' => 'boolean'],
                ['key' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'number'],
                ['key' => 'difficulty', 'label' => 'Difficulty', 'type' => 'select', 'options' => [
                    ['key' => 'beginner', 'label' => 'Beginner'],
                    ['key' => 'intermediate', 'label' => 'Intermediate'],
                    ['key' => 'advanced', 'label' => 'Advanced'],
                ]],
            ],
            'sea-safari' => [
                ['key' => 'vehicle_type', 'label' => 'Vehicle Type', 'type' => 'select', 'options' => [
                    ['key' => 'boat', 'label' => 'Boat Only'],
                    ['key' => 'jeep_combo', 'label' => 'Boat + Jeep Combo'],
                ]],
                ['key' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'number'],
                ['key' => 'meals_included', 'label' => 'Meals Included', 'type' => 'boolean'],
                ['key' => 'min_age', 'label' => 'Minimum Age', 'type' => 'number'],
            ],
            'island-trips' => [
                ['key' => 'number_of_islands', 'label' => 'Number of Islands', 'type' => 'number'],
                ['key' => 'lunch_included', 'label' => 'Lunch Included', 'type' => 'boolean'],
                ['key' => 'snorkeling_included', 'label' => 'Snorkeling Included', 'type' => 'boolean'],
                ['key' => 'transport_type', 'label' => 'Transport Type', 'type' => 'select', 'options' => [
                    ['key' => 'speedboat', 'label' => 'Speedboat'],
                    ['key' => 'ferry', 'label' => 'Ferry'],
                ]],
            ],
            'dolphin-watching' => [
                ['key' => 'sighting_guarantee', 'label' => 'Sighting Guarantee', 'type' => 'boolean'],
                ['key' => 'swim_with_dolphins', 'label' => 'Swim With Dolphins', 'type' => 'boolean'],
                ['key' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'number'],
                ['key' => 'boat_type', 'label' => 'Boat Type', 'type' => 'select', 'options' => [
                    ['key' => 'speedboat', 'label' => 'Speedboat'],
                    ['key' => 'catamaran', 'label' => 'Catamaran'],
                ]],
            ],
            'sailing' => [
                ['key' => 'vessel_type', 'label' => 'Vessel Type', 'type' => 'select', 'options' => [
                    ['key' => 'sailboat', 'label' => 'Sailboat'],
                    ['key' => 'catamaran', 'label' => 'Catamaran'],
                    ['key' => 'traditional_dhow', 'label' => 'Traditional Dhow'],
                ]],
                ['key' => 'skipper_included', 'label' => 'Skipper Included', 'type' => 'boolean'],
                ['key' => 'duration_hours', 'label' => 'Duration (hours)', 'type' => 'number'],
                ['key' => 'sunset_option', 'label' => 'Sunset Option', 'type' => 'boolean'],
            ],
        ];

        $seededCategories = [];

        foreach ($definitions as $categorySlug => $features) {
            $category = Category::where('slug', $categorySlug)->first();

            if (! $category) {
                $this->command->warn("FeatureDefinitionSeeder: category '{$categorySlug}' not found, skipping — run CategorySeeder first.");

                continue;
            }

            foreach ($features as $sortOrder => $feature) {
                FeatureDefinition::firstOrCreate(
                    ['category_id' => $category->id, 'key' => $feature['key']],
                    [
                        'label' => $feature['label'],
                        'type' => $feature['type'],
                        'options' => $feature['options'] ?? null,
                        'is_required' => $feature['is_required'] ?? false,
                        'sort_order' => $sortOrder,
                    ]
                );
            }

            $seededCategories[] = $category->name;
        }

        $this->command->info('Feature definitions seeded for: ' . implode(', ', $seededCategories));
    }
}
