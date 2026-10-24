<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesToRunInProduction;
use App\Models\Activity;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ActivitySeeder extends Seeder
{
    use RefusesToRunInProduction;

    /**
     * Global counter driving deterministic phone-free variation: provider
     * round-robin, location cycling, override placement, etc. Reset per run.
     */
    protected int $globalIndex = 0;

    /**
     * Per-category template data. day_period_times gives two [start, end]
     * time pairs used across the week (the second pair includes an
     * overnight example for categories where a night excursion makes
     * sense — diving, fishing, sailing, dolphin watching).
     */
    protected function categoryTemplates(): array
    {
        return [
            'diving' => [
                'locations' => ['Sharm El Sheikh', 'Hurghada', 'Dahab', 'Marsa Alam', 'Nuweiba'],
                'meeting_points' => ['Marina Dive Center, Dock 1', 'Reef House Jetty', 'Blue Hole Access Point'],
                'title_fragments' => ['Night Dive', 'Wreck Dive Adventure', 'Reef Exploration Dive', 'Drift Dive Experience', 'Deep Wall Dive', 'Coral Garden Dive'],
                'description' => 'A guided scuba diving excursion exploring %s\'s underwater reef system, led by certified local dive instructors.',
                'duration_minutes' => [60, 90, 120, 150],
                'min_participants' => [1, 2],
                'max_participants' => [6, 8, 10],
                'min_age' => [10, 12, 14],
                'day_period_times' => [['09:00', '13:00'], ['19:00', '05:00']],
                'price_range' => [700, 1300],
                'feature_values' => ['dive_depth' => [12, 18, 25, 30], 'certification_required' => [true, false], 'equipment_included' => [['bcd', 'regulator'], ['bcd', 'regulator', 'tank', 'fins']], 'instructor_included' => [true, false], 'difficulty' => ['beginner', 'intermediate', 'advanced']],
                'options' => [['Underwater Photos Package', 25], ['Private Instructor', 80], ['Extra Tank', 20]],
            ],
            'snorkeling' => [
                'locations' => ['Dahab', 'Marsa Alam', 'Hurghada', 'Sharm El Sheikh'],
                'meeting_points' => ['Coral Garden Jetty', 'Lagoon Beach Access', 'Snorkel Center Dock'],
                'title_fragments' => ['Coral Garden Snorkel Trip', 'Lagoon Snorkeling Tour', 'Reef Snorkel Excursion', 'Sunset Snorkel Trip', 'Family Snorkel Adventure'],
                'description' => 'A relaxed half-day snorkeling trip over %s\'s shallow coral gardens, suitable for all swimming levels.',
                'duration_minutes' => [90, 120, 180],
                'min_participants' => [1, 2],
                'max_participants' => [10, 15, 20],
                'min_age' => [6, 8, 10],
                'day_period_times' => [['09:00', '12:00'], ['15:00', '18:00']],
                'price_range' => [300, 600],
                'feature_values' => ['equipment_included' => [true, false], 'guide_included' => [true, false], 'boat_type' => ['speedboat', 'sailboat', 'catamaran'], 'duration_hours' => [2, 3, 4], 'marine_life' => ['Parrotfish, clownfish, sea turtles', 'Reef sharks, rays, colorful coral']],
                'options' => [['Underwater Camera Rental', 15], ['Fish Feed Pack', 5], ['Beach Towel Rental', 5]],
            ],
            'fishing' => [
                'locations' => ['Aswan', 'Alexandria', 'Hurghada', 'Red Sea Coast'],
                'meeting_points' => ['Fishing Pier Dock 2', 'Harbor Charter Office', 'Angling Club Jetty'],
                'title_fragments' => ['Deep Sea Fishing Charter', 'Reef Fishing Trip', 'Sunrise Fishing Expedition', 'Night Fishing Adventure', 'Sport Fishing Excursion'],
                'description' => 'A chartered fishing trip departing from %s, targeting local game fish with experienced crew.',
                'duration_minutes' => [180, 240, 300],
                'min_participants' => [1, 2],
                'max_participants' => [4, 6, 8],
                'min_age' => [8, 10],
                'day_period_times' => [['06:00', '10:00'], ['18:00', '23:00']],
                'price_range' => [900, 1600],
                'feature_values' => ['fishing_type' => ['deep_sea', 'reef', 'fly_fishing'], 'equipment_included' => [true, false], 'boat_type' => ['speedboat', 'yacht', 'traditional'], 'experience_required' => [false, true], 'maximum_fishermen' => [4, 6, 8]],
                'options' => [['Bait & Tackle Package', 30], ['Fish Cleaning Service', 15], ['Extra Rod Rental', 10]],
            ],
            'boat-trips' => [
                'locations' => ['Hurghada', 'El Gouna', 'Sharm El Sheikh'],
                'meeting_points' => ['Marina Boat Dock', 'Harbor Terminal 1', 'Golden Coast Pier'],
                'title_fragments' => ['Coastal Boat Cruise', 'Full-Day Boat Trip', 'Sunset Boat Tour', 'Island Hopper Boat Trip', 'Bay Cruise'],
                'description' => 'A scenic boat excursion along %s\'s coastline, including stops at popular swimming spots.',
                'duration_minutes' => [120, 240, 360],
                'min_participants' => [2, 4],
                'max_participants' => [15, 20, 25],
                'min_age' => [0, 5],
                'day_period_times' => [['10:00', '14:00'], ['15:00', '19:00']],
                'price_range' => [400, 900],
                'feature_values' => ['boat_type' => ['speedboat', 'yacht', 'catamaran'], 'duration_hours' => [2, 4, 6], 'refreshments_included' => [true, false], 'max_capacity' => [15, 20, 25]],
                'options' => [['BBQ Lunch Add-on', 20], ['Snorkel Gear Rental', 10], ['Private Cabin', 60]],
            ],
            'yacht-trips' => [
                'locations' => ['Sharm El Sheikh', 'El Gouna', 'Hurghada'],
                'meeting_points' => ['Sultan Marina Berth 4', 'Luxury Yacht Terminal', 'Private Charter Dock'],
                'title_fragments' => ['Private Yacht Charter', 'Luxury Yacht Day Trip', 'Sunset Yacht Cruise', 'VIP Yacht Experience'],
                'description' => 'A private yacht charter departing from %s, with dedicated crew and premium onboard amenities.',
                'duration_minutes' => [180, 300, 480],
                'min_participants' => [2, 4],
                'max_participants' => [8, 12, 16],
                'min_age' => [0],
                'day_period_times' => [['11:00', '15:00'], ['16:00', '21:00']],
                'price_range' => [2500, 6000],
                'feature_values' => ['yacht_size_ft' => [45, 60, 80], 'crew_included' => [true], 'catering_included' => [true, false], 'luxury_tier' => ['standard', 'premium', 'vip']],
                'options' => [['Champagne Service', 100], ['Onboard Chef', 250], ['Water Sports Equipment', 80]],
            ],
            'parasailing' => [
                'locations' => ['Hurghada', 'Sharm El Sheikh'],
                'meeting_points' => ['Skyline Beach Launch Point', 'Watersports Center Dock'],
                'title_fragments' => ['Tandem Parasailing Flight', 'Solo Parasailing Adventure', 'Sunset Parasail Experience'],
                'description' => 'A parasailing flight over %s\'s coastline, offering panoramic aerial views of the bay.',
                'duration_minutes' => [15, 20, 30],
                'min_participants' => [1],
                'max_participants' => [2, 3],
                'min_age' => [8, 10],
                'day_period_times' => [['10:00', '13:00'], ['14:00', '17:00']],
                'price_range' => [250, 450],
                'feature_values' => ['max_height_m' => [100, 150, 200], 'tandem_available' => [true, false], 'min_weight_kg' => [30, 35, 40], 'photo_package_available' => [true, false]],
                'options' => [['Photo & Video Package', 20], ['Extended Flight Time', 15]],
            ],
            'jet-ski' => [
                'locations' => ['Sharm El Sheikh', 'Hurghada'],
                'meeting_points' => ['Aqua Jet Rental Point', 'Beachfront Watersports Hub'],
                'title_fragments' => ['Jet Ski Safari', 'Solo Jet Ski Rental', 'Guided Jet Ski Tour', 'Jet Ski Adventure Ride'],
                'description' => 'A jet ski excursion along %s\'s coastline, available as a guided tour or independent rental.',
                'duration_minutes' => [30, 45, 60],
                'min_participants' => [1],
                'max_participants' => [1, 2],
                'min_age' => [16, 18],
                'day_period_times' => [['09:00', '12:00'], ['13:00', '17:00']],
                'price_range' => [350, 700],
                'feature_values' => ['engine_power_cc' => [900, 1100, 1500], 'license_required' => [false, true], 'guided_tour' => [true, false], 'max_speed_kmh' => [60, 80, 100]],
                'options' => [['Extra 15 Minutes', 15], ['GoPro Rental', 10]],
            ],
            'kayaking' => [
                'locations' => ['Nabq Bay', 'Dahab', 'Marsa Alam'],
                'meeting_points' => ['Mangrove Kayak Launch', 'Lagoon Access Point'],
                'title_fragments' => ['Mangrove Kayak Tour', 'Sunset Kayaking Trip', 'Coastal Kayak Adventure', 'Guided Sea Kayak Tour'],
                'description' => 'A guided kayaking tour through %s\'s calm coastal waters and mangrove channels.',
                'duration_minutes' => [60, 90, 120],
                'min_participants' => [1, 2],
                'max_participants' => [8, 10],
                'min_age' => [8, 10],
                'day_period_times' => [['08:00', '11:00'], ['16:00', '19:00']],
                'price_range' => [200, 450],
                'feature_values' => ['kayak_type' => ['single', 'double', 'inflatable'], 'guided' => [true, false], 'duration_hours' => [1, 2, 3], 'difficulty' => ['beginner', 'intermediate']],
                'options' => [['Double Kayak Upgrade', 15], ['Waterproof Bag Rental', 5]],
            ],
            'sea-safari' => [
                'locations' => ['Marsa Alam', 'Sharm El Sheikh'],
                'meeting_points' => ['Desert Sea Safari Base Camp', 'Combo Tour Departure Point'],
                'title_fragments' => ['Desert & Sea Safari Combo', 'Full-Day Sea Safari', 'Bedouin Sea Safari Experience'],
                'description' => 'A combined sea and desert safari excursion departing from %s, blending marine and Bedouin desert experiences.',
                'duration_minutes' => [240, 360, 480],
                'min_participants' => [2, 4],
                'max_participants' => [12, 16],
                'min_age' => [6, 8],
                'day_period_times' => [['08:00', '13:00'], ['14:00', '19:00']],
                'price_range' => [600, 1200],
                'feature_values' => ['vehicle_type' => ['boat', 'jeep_combo'], 'duration_hours' => [4, 6, 8], 'meals_included' => [true, false], 'min_age' => [6, 8, 10]],
                'options' => [['Bedouin Dinner Add-on', 25], ['Camel Ride Extension', 20]],
            ],
            'island-trips' => [
                'locations' => ['Hurghada', 'Marsa Alam', 'Sataya'],
                'meeting_points' => ['Island Hopper Terminal', 'Harbor Ferry Dock'],
                'title_fragments' => ['Multi-Island Hopping Tour', 'Paradise Island Day Trip', 'Two-Island Snorkel & Sun Trip'],
                'description' => 'A full-day island-hopping trip visiting pristine islands near %s, with time for swimming and relaxing.',
                'duration_minutes' => [300, 420, 480],
                'min_participants' => [2, 4],
                'max_participants' => [20, 25, 30],
                'min_age' => [0, 5],
                'day_period_times' => [['08:00', '14:00'], ['15:00', '20:00']],
                'price_range' => [500, 950],
                'feature_values' => ['number_of_islands' => [2, 3, 4], 'lunch_included' => [true, false], 'snorkeling_included' => [true, false], 'transport_type' => ['speedboat', 'ferry']],
                'options' => [['Buffet Lunch Upgrade', 15], ['Private Cabana', 30]],
            ],
            'dolphin-watching' => [
                'locations' => ['Sataya', 'Hurghada', 'Marsa Alam'],
                'meeting_points' => ['Dolphin House Departure Point', 'Marine Sanctuary Dock'],
                'title_fragments' => ['Dolphin House Boat Trip', 'Wild Dolphin Watching Tour', 'Swim With Dolphins Excursion'],
                'description' => 'A boat trip to %s\'s dolphin sanctuary, offering the chance to observe (and sometimes swim with) wild spinner dolphins.',
                'duration_minutes' => [180, 240, 300],
                'min_participants' => [2, 4],
                'max_participants' => [15, 20],
                'min_age' => [5, 8],
                'day_period_times' => [['07:00', '11:00'], ['12:00', '16:00']],
                'price_range' => [550, 900],
                'feature_values' => ['sighting_guarantee' => [false, true], 'swim_with_dolphins' => [true, false], 'duration_hours' => [3, 4, 5], 'boat_type' => ['speedboat', 'catamaran']],
                'options' => [['Underwater Camera Package', 20], ['Wetsuit Rental', 10]],
            ],
            'sailing' => [
                'locations' => ['El Gouna', 'Hurghada', 'Sharm El Sheikh'],
                'meeting_points' => ['Windward Sailing Club Dock', 'Marina Sailing Berth 2'],
                'title_fragments' => ['Sunset Sailing Cruise', 'Full-Day Sailing Trip', 'Traditional Dhow Sailing Tour', 'Catamaran Sailing Excursion'],
                'description' => 'A sailing excursion along %s\'s coastline aboard a traditional or modern sailing vessel.',
                'duration_minutes' => [120, 180, 300],
                'min_participants' => [2, 4],
                'max_participants' => [10, 14],
                'min_age' => [0, 5],
                'day_period_times' => [['10:00', '13:00'], ['18:00', '21:00']],
                'price_range' => [450, 950],
                'feature_values' => ['vessel_type' => ['sailboat', 'catamaran', 'traditional_dhow'], 'skipper_included' => [true], 'duration_hours' => [2, 3, 5], 'sunset_option' => [true, false]],
                'options' => [['Onboard Snacks & Drinks', 15], ['Sunset Photography Package', 20]],
            ],
        ];
    }

    protected function sharedRequirementsPool(): array
    {
        return [
            'Valid government-issued ID required',
            'Please arrive 30 minutes before departure',
            'Basic swimming ability recommended',
            'Bring appropriate swimwear and sunscreen',
            'Medical declaration required for guests over 50',
            'Not recommended for pregnant guests',
            'Comfortable non-slip footwear recommended',
        ];
    }

    protected function sharedInclusionsPool(): array
    {
        return ['Safety equipment', 'Professional guide', 'Bottled water', 'Life jackets', 'Hotel transportation'];
    }

    protected function sharedExclusionsPool(): array
    {
        return ['Personal expenses', 'Gratuities/tips', 'Alcoholic beverages', 'Professional photography', 'Travel insurance'];
    }

    protected function rejectionReasonsPool(): array
    {
        return [
            'Missing required safety certification details.',
            'Meeting point address is incomplete or unclear.',
            'Pricing information does not match the schedule provided.',
            'Insufficient description of included equipment.',
        ];
    }

    public function run(): void
    {
        $this->refuseInProduction();

        $providers = Provider::orderBy('id')->get();

        if ($providers->isEmpty()) {
            $this->command->warn('ActivitySeeder skipped: run ProviderSeeder first.');

            return;
        }

        $statusPlan = ['draft', 'draft', 'pending_review', 'pending_review', 'approved', 'approved', 'rejected', 'rejected', 'published', 'published', 'published', 'inactive'];

        $counts = ['activities' => 0, 'images' => 0, 'features' => 0, 'options' => 0, 'requirements' => 0, 'inclusions' => 0, 'exclusions' => 0, 'periods' => 0, 'overrides' => 0, 'booking_options' => 0, 'pricing_rules' => 0];
        $statusTally = [];
        $categoryTally = [];

        foreach ($this->categoryTemplates() as $slug => $template) {
            $category = Category::where('slug', $slug)->first();

            if (! $category) {
                $this->command->warn("ActivitySeeder: category '{$slug}' not found, skipping.");

                continue;
            }

            $categoryTally[$category->name] = 0;

            foreach ($statusPlan as $planIndex => $status) {
                $activitySlug = "{$slug}-" . ($planIndex + 1);
                $provider = $providers[$this->globalIndex % $providers->count()];
                $location = $template['locations'][$this->globalIndex % count($template['locations'])];
                $titleFragment = $template['title_fragments'][$planIndex % count($template['title_fragments'])];
                $title = "{$titleFragment} - {$location}";

                $existing = Activity::where('slug', $activitySlug)->first();

                $isRejected = $status === 'rejected';
                $isPublishedLike = in_array($status, ['published', 'inactive'], true);

                $activity = Activity::firstOrCreate(
                    ['slug' => $activitySlug],
                    [
                        'provider_id' => $provider->id,
                        'category_id' => $category->id,
                        'title' => $title,
                        'description' => sprintf($template['description'], $location),
                        'location' => $location,
                        'meeting_point' => $template['meeting_points'][$this->globalIndex % count($template['meeting_points'])],
                        'lat' => round(27.9 + (($this->globalIndex % 10) - 5) * 0.3, 6),
                        'lng' => round(33.8 + (($this->globalIndex % 8) - 4) * 0.35, 6),
                        'duration_minutes' => $template['duration_minutes'][$this->globalIndex % count($template['duration_minutes'])],
                        'min_participants' => $template['min_participants'][$this->globalIndex % count($template['min_participants'])],
                        'max_participants' => $template['max_participants'][$this->globalIndex % count($template['max_participants'])],
                        'min_age' => $template['min_age'][$this->globalIndex % count($template['min_age'])],
                        'status' => $status,
                        'rejection_reason' => $isRejected ? $this->rejectionReasonsPool()[$planIndex % count($this->rejectionReasonsPool())] : null,
                        'published_at' => $isPublishedLike ? now()->subDays(($this->globalIndex % 60) + 1) : null,
                    ]
                );

                $this->globalIndex++;

                $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
                $categoryTally[$category->name]++;

                if (! $activity->wasRecentlyCreated && $existing) {
                    // Already fully seeded on a prior run — skip attaching
                    // children again to keep repeated `db:seed` calls safe.
                    $counts['activities']++;

                    continue;
                }

                $counts['activities']++;

                if ($isPublishedLike) {
                    $counts['images'] += $this->attachImages($activity);
                }

                $counts['features'] += $this->attachFeatureValues($activity, $category, $template);
                $counts['options'] += $this->attachOptions($activity, $template);
                $counts['requirements'] += $this->attachRequirements($activity);
                [$inclusions, $exclusions] = $this->attachInclusionsAndExclusions($activity);
                $counts['inclusions'] += $inclusions;
                $counts['exclusions'] += $exclusions;
                $counts['periods'] += $this->attachSchedule($activity, $template, $planIndex);
                [$bookingOptions, $pricingRules] = $this->attachBookingOptions($activity, $slug, $template['price_range'], $planIndex);
                $counts['booking_options'] += $bookingOptions;
                $counts['pricing_rules'] += $pricingRules;

                if ($planIndex % 5 === 0) {
                    $counts['overrides'] += $this->attachOverrides($activity, $planIndex);
                }
            }
        }

        $this->command->info('--- ActivitySeeder summary ---');
        $this->command->info('Activities: ' . $counts['activities']);
        $this->command->info('By status: ' . json_encode($statusTally));
        $this->command->info('By category: ' . json_encode($categoryTally));
        $this->command->info('Images: ' . $counts['images'] . ' | Feature values: ' . $counts['features'] . ' | Options: ' . $counts['options']);
        $this->command->info('Requirements: ' . $counts['requirements'] . ' | Inclusions: ' . $counts['inclusions'] . ' | Exclusions: ' . $counts['exclusions']);
        $this->command->info('Schedule periods: ' . $counts['periods'] . ' | Overrides: ' . $counts['overrides']);
        $this->command->info('Booking options: ' . $counts['booking_options'] . ' | Pricing rules: ' . $counts['pricing_rules']);
    }

    /**
     * 2-4 images, deterministic placeholder paths (per the task's explicit
     * allowance — no real files exist on disk, but ActivityImageResource's
     * Storage::url() call doesn't check file existence, so this is safe
     * for demo/API-shape purposes). First image is the cover, matching
     * ActivityImageService::upload()'s real business rule.
     */
    protected function attachImages(Activity $activity): int
    {
        $count = 2 + ($activity->id % 3); // 2, 3, or 4

        for ($i = 0; $i < $count; $i++) {
            $activity->images()->create([
                'path' => "activities/placeholder-{$activity->id}-{$i}.jpg",
                'sort_order' => $i,
                'is_cover' => $i === 0,
            ]);
        }

        return $count;
    }

    /**
     * Only ever attaches values for feature definitions belonging to the
     * ACTIVITY'S OWN category — mirrors ActivityFeatureService::sync()'s
     * cross-category rejection rule exactly, by construction (we only ever
     * look up $category->id's own definitions).
     */
    protected function attachFeatureValues(Activity $activity, Category $category, array $template): int
    {
        $definitions = FeatureDefinition::where('category_id', $category->id)->get()->keyBy('key');
        $count = 0;

        foreach ($template['feature_values'] as $key => $possibleValues) {
            $definition = $definitions->get($key);

            if (! $definition) {
                continue;
            }

            $value = $possibleValues[$activity->id % count($possibleValues)];

            $columns = match ($definition->type) {
                'text', 'select' => ['value_text' => is_array($value) ? $value[0] : $value],
                'number' => ['value_number' => $value],
                'boolean' => ['value_boolean' => (bool) $value],
                'multi_select' => ['value_json' => is_array($value) ? $value : [$value]],
            };

            $activity->featureValues()->create(array_merge(['feature_definition_id' => $definition->id], $columns));
            $count++;
        }

        return $count;
    }

    protected function attachOptions(Activity $activity, array $template): int
    {
        $pool = $template['options'];
        $count = 2 + ($activity->id % 2); // 2 or 3
        $selected = array_slice($pool, 0, min($count, count($pool)));

        foreach ($selected as $index => [$name, $price]) {
            $activity->options()->create([
                'name' => $name,
                'extra_price' => $price,
                'is_required' => false,
                'sort_order' => $index,
            ]);
        }

        return count($selected);
    }

    protected function attachRequirements(Activity $activity): int
    {
        $pool = $this->sharedRequirementsPool();
        $count = 2 + ($activity->id % 2); // 2 or 3
        $offset = $activity->id % count($pool);

        for ($i = 0; $i < $count; $i++) {
            $activity->requirements()->create([
                'text' => $pool[($offset + $i) % count($pool)],
                'is_mandatory' => $i === 0,
                'sort_order' => $i,
            ]);
        }

        return $count;
    }

    protected function attachInclusionsAndExclusions(Activity $activity): array
    {
        $inclusionsPool = $this->sharedInclusionsPool();
        $exclusionsPool = $this->sharedExclusionsPool();

        $inclusionCount = 2 + ($activity->id % 2); // 2 or 3
        $exclusionCount = 1 + ($activity->id % 3); // 1, 2, or 3

        $sortOrder = 0;

        for ($i = 0; $i < $inclusionCount; $i++) {
            $activity->inclusions()->create([
                'type' => 'included',
                'description' => $inclusionsPool[($activity->id + $i) % count($inclusionsPool)],
                'sort_order' => $sortOrder++,
            ]);
        }

        for ($i = 0; $i < $exclusionCount; $i++) {
            $activity->inclusions()->create([
                'type' => 'excluded',
                'description' => $exclusionsPool[($activity->id + $i) % count($exclusionsPool)],
                'sort_order' => $sortOrder++,
            ]);
        }

        return [$inclusionCount, $exclusionCount];
    }

    /**
     * Every activity gets full 7-day coverage, 2 periods per day, using
     * the category's two time templates. The second template is an
     * overnight period for roughly every 3rd activity (planIndex % 3),
     * giving genuine overnight coverage across categories without every
     * single activity being an overnight example. Roughly 1 in 6 periods
     * is set inactive, so AvailabilityService's inactive-period exclusion
     * has real data to prove against.
     */
    protected function attachSchedule(Activity $activity, array $template, int $planIndex): int
    {
        [$dayStart, $dayEnd] = $template['day_period_times'][0];
        $useOvernight = $planIndex % 3 === 0;
        [$nightStart, $nightEnd] = $useOvernight
            ? $template['day_period_times'][1]
            : ['15:00', '18:00'];

        [$priceLow, $priceHigh] = $template['price_range'];
        $count = 0;

        for ($dayOfWeek = 0; $dayOfWeek <= 6; $dayOfWeek++) {
            $periods = [
                ['start' => $dayStart, 'end' => $dayEnd, 'price' => $priceLow + (($dayOfWeek * 37) % ($priceHigh - $priceLow))],
                ['start' => $nightStart, 'end' => $nightEnd, 'price' => $priceLow + (($dayOfWeek * 53) % ($priceHigh - $priceLow))],
            ];

            foreach ($periods as $periodIndex => $period) {
                $isInactive = ($activity->id + $dayOfWeek + $periodIndex) % 6 === 0;

                $activity->schedulePeriods()->create([
                    'day_of_week' => $dayOfWeek,
                    'start_time' => $period['start'],
                    'end_time' => $period['end'],
                    'crosses_midnight' => $period['start'] > $period['end'],
                    'instructions' => $periodIndex === 0
                        ? 'Please arrive 30 minutes before departure.'
                        : 'Bring valid identification and appropriate clothing.',
                    'status' => $isInactive ? 'inactive' : 'active',
                    'sort_order' => $periodIndex,
                ]);

                $count++;
            }
        }

        return $count;
    }

    /**
     * A subset of activities (every 5th, via the planIndex % 5 caller
     * check) gets one of the four override patterns, cycled by planIndex,
     * covering: single-day price override, date-range price override,
     * single-day blackout, and date-range blackout.
     *
     * Anchored to now()+30 days rather than fixed calendar dates: the
     * original hardcoded 2026-09-* dates meant that seeding after that
     * month planted overrides entirely in the past, making the demo
     * availability data meaningless. The original relative offsets
     * (09-10 -> 09-15..09-20 -> 09-22 -> 09-25..09-27) are preserved as
     * +0, +5..+10, +12 and +15..+17 days from the anchor.
     */
    protected function attachOverrides(Activity $activity, int $planIndex): int
    {
        $anchor = now()->addDays(30)->startOfDay();
        $pattern = $planIndex % 4;

        $override = match ($pattern) {
            0 => ['date_from' => $anchor->toDateString(), 'date_to' => $anchor->toDateString(), 'is_unavailable' => false, 'price' => 1500],
            1 => ['date_from' => $anchor->copy()->addDays(5)->toDateString(), 'date_to' => $anchor->copy()->addDays(10)->toDateString(), 'is_unavailable' => false, 'price' => 1800],
            2 => ['date_from' => $anchor->copy()->addDays(12)->toDateString(), 'date_to' => $anchor->copy()->addDays(12)->toDateString(), 'is_unavailable' => true],
            default => ['date_from' => $anchor->copy()->addDays(15)->toDateString(), 'date_to' => $anchor->copy()->addDays(17)->toDateString(), 'is_unavailable' => true],
        };

        $activity->availabilityOverrides()->create($override);

        return 1;
    }

    /**
     * Category-appropriate booking-option recipes (spec §36 examples):
     * Diving/Snorkeling/Fishing/Boat Trips/Kayaking/Sea Safari/Island
     * Trips/Dolphin Watching center on per_person adult+child pricing;
     * Yacht/Sailing offer per_hour+per_trip(+package); Jet Ski is
     * per_unit; Parasailing is fixed. Each recipe's price is derived from
     * the category's own price_range (the same $priceLow/$priceHigh data
     * that sat dormant in schedule-period generation since Module 08
     * Step 2 removed price from schedule periods).
     */
    protected function bookingOptionRecipes(): array
    {
        return [
            'diving' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.5]],
            ],
            'snorkeling' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.5]],
                ['name' => 'Private Trip', 'model' => 'per_trip', 'multiplier' => 3.0],
            ],
            'fishing' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.6]],
                ['name' => 'Private Charter', 'model' => 'per_trip', 'multiplier' => 4.0],
            ],
            'boat-trips' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.5]],
            ],
            'yacht-trips' => [
                ['name' => 'Per Hour', 'model' => 'per_hour', 'multiplier' => 1.0, 'min_duration' => 2, 'max_duration' => 8, 'duration_unit' => 'hour'],
                ['name' => 'Per Trip', 'model' => 'per_trip', 'multiplier' => 4.0],
                ['name' => '4 Hour Package', 'model' => 'package', 'multiplier' => 3.5, 'min_duration' => 4, 'duration_unit' => 'hour'],
            ],
            'parasailing' => [
                ['name' => 'Standard Flight', 'model' => 'fixed', 'multiplier' => 1.0],
            ],
            'jet-ski' => [
                ['name' => 'Per Unit', 'model' => 'per_unit', 'multiplier' => 1.0, 'min_quantity' => 1, 'max_quantity' => 4],
            ],
            'kayaking' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.6]],
            ],
            'sea-safari' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.6]],
                ['name' => 'Private Group', 'model' => 'per_group', 'multiplier' => 3.0, 'min_participants' => 2, 'max_participants' => 6],
            ],
            'island-trips' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.5]],
            ],
            'dolphin-watching' => [
                ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.5]],
            ],
            'sailing' => [
                ['name' => 'Per Hour', 'model' => 'per_hour', 'multiplier' => 1.0, 'min_duration' => 2, 'max_duration' => 6, 'duration_unit' => 'hour'],
                ['name' => 'Per Trip', 'model' => 'per_trip', 'multiplier' => 3.0],
            ],
        ];
    }

    /**
     * Creates every booking option (and its pricing rule(s)) for one
     * activity, per its category's recipe list. Every activity gets
     * these regardless of moderation status, matching the same reasoning
     * as attachSchedule() — a provider configures pricing before/
     * independent of admin moderation.
     *
     * @return array{0: int, 1: int} [booking option count, pricing rule count]
     */
    protected function attachBookingOptions(Activity $activity, string $categorySlug, array $priceRange, int $planIndex): array
    {
        $recipes = $this->bookingOptionRecipes()[$categorySlug] ?? [
            ['name' => 'Standard', 'model' => 'per_person', 'participants' => ['adult' => 1.0, 'child' => 0.5]],
        ];

        [$priceLow, $priceHigh] = $priceRange;
        $spread = max(1, $priceHigh - $priceLow);
        $basePrice = $priceLow + (($activity->id * 37) % $spread);

        $optionCount = 0;
        $ruleCount = 0;

        foreach ($recipes as $index => $recipe) {
            $optionData = [
                'name' => $recipe['name'],
                'slug' => \Illuminate\Support\Str::slug($recipe['name']),
                'booking_model' => $recipe['model'],
                'is_default' => $index === 0,
                'sort_order' => $index,
            ];

            foreach (['min_duration', 'max_duration', 'duration_unit', 'min_quantity', 'max_quantity', 'min_participants', 'max_participants'] as $field) {
                if (isset($recipe[$field])) {
                    $optionData[$field] = $recipe[$field];
                }
            }

            $option = $activity->bookingOptions()->create($optionData);
            $optionCount++;

            if (isset($recipe['participants'])) {
                foreach ($recipe['participants'] as $participantType => $ratio) {
                    $option->pricingRules()->create([
                        'participant_type' => $participantType,
                        'price' => round($basePrice * $ratio, 2),
                    ]);
                    $ruleCount++;
                }
            } else {
                $option->pricingRules()->create([
                    'price' => round($basePrice * ($recipe['multiplier'] ?? 1.0), 2),
                ]);
                $ruleCount++;
            }
        }

        return [$optionCount, $ruleCount];
    }
}
