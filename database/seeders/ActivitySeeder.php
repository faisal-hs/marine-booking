<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ActivitySeeder extends Seeder
{
    /**
     * Seeds activities across every status so the admin moderation queue
     * (Module 06) and the provider's own activity list have real data to
     * exercise, instead of every admin page being empty. Attaches feature
     * values, options, requirements, and inclusions to the two fullest
     * examples to demonstrate the whole Module 05 data model end to end.
     */
    public function run(): void
    {
        $provider = Provider::where('slug', 'red-sea-divers')->first() ?? Provider::first();
        $diving = Category::where('slug', 'diving')->first();
        $snorkeling = Category::where('slug', 'snorkeling')->first();

        if (! $provider || ! $diving) {
            $this->command->warn('ActivitySeeder skipped: run DemoUsersSeeder and CategorySeeder first.');

            return;
        }

        // --- Published: a fully fleshed-out diving activity ---
        $published = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $diving->id,
            'title' => 'Red Sea Night Dive',
            'slug' => 'red-sea-night-dive-' . Str::random(6),
            'description' => 'A guided night dive along the reef, spotting nocturnal marine life under torchlight.',
            'location' => 'Sharm El Sheikh',
            'meeting_point' => 'Marina Dive Center, Dock 3',
            'duration_minutes' => 90,
            'min_participants' => 2,
            'max_participants' => 8,
            'min_age' => 14,
            'status' => 'published',
            'published_at' => now()->subDays(10),
        ]);

        $this->attachDivingFeatures($published, $diving, [
            'dive_depth' => 18,
            'certification_required' => true,
            'equipment_included' => ['bcd', 'regulator', 'tank'],
            'instructor_included' => true,
        ]);

        $published->options()->create(['name' => 'Underwater Photos Package', 'extra_price' => 25, 'sort_order' => 0]);
        $published->options()->create(['name' => 'Private Instructor', 'extra_price' => 80, 'max_quantity' => 1, 'sort_order' => 1]);

        $published->requirements()->create(['text' => 'Open Water certification or higher', 'is_mandatory' => true, 'sort_order' => 0]);
        $published->requirements()->create(['text' => 'Minimum age 14', 'is_mandatory' => true, 'sort_order' => 1]);

        $published->inclusions()->create(['type' => 'included', 'description' => 'Wetsuit', 'sort_order' => 0]);
        $published->inclusions()->create(['type' => 'included', 'description' => 'Dive tank and weights', 'sort_order' => 1]);
        $published->inclusions()->create(['type' => 'excluded', 'description' => 'Underwater camera rental', 'sort_order' => 2]);

        // --- Pending review: two activities waiting in the moderation queue ---
        $pendingOne = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $diving->id,
            'title' => 'Wreck Diving Adventure',
            'slug' => 'wreck-diving-adventure-' . Str::random(6),
            'description' => 'Explore a historic shipwreck resting at 25 meters.',
            'location' => 'Hurghada',
            'duration_minutes' => 120,
            'min_participants' => 1,
            'max_participants' => 6,
            'status' => 'pending_review',
        ]);

        $this->attachDivingFeatures($pendingOne, $diving, [
            'dive_depth' => 25,
            'certification_required' => true,
        ]);

        if ($snorkeling) {
            Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $snorkeling->id,
                'title' => 'Coral Garden Snorkeling Trip',
                'slug' => 'coral-garden-snorkeling-' . Str::random(6),
                'description' => 'A relaxed half-day snorkeling trip over shallow coral gardens.',
                'location' => 'Dahab',
                'duration_minutes' => 180,
                'min_participants' => 2,
                'max_participants' => 15,
                'status' => 'pending_review',
            ]);
        }

        // --- Rejected: demonstrates the rejection_reason field being used ---
        Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $diving->id,
            'title' => 'Unsafe Cave Dive (incomplete listing)',
            'slug' => 'cave-dive-rejected-' . Str::random(6),
            'description' => 'Cave diving experience.',
            'status' => 'rejected',
            'rejection_reason' => 'Missing required safety certification details and emergency contact information.',
        ]);

        // --- Draft: not yet submitted by the provider ---
        Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $diving->id,
            'title' => 'Untitled Diving Trip',
            'slug' => 'untitled-diving-trip-' . Str::random(6),
            'status' => 'draft',
        ]);

        // --- Inactive: was published, later deactivated ---
        Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $diving->id,
            'title' => 'Seasonal Whale Shark Snorkel (off-season)',
            'slug' => 'whale-shark-snorkel-' . Str::random(6),
            'description' => 'Seasonal excursion, currently paused until whale shark season returns.',
            'status' => 'inactive',
            'published_at' => now()->subMonths(6),
        ]);

        $this->command->info('Activities seeded: 1 published, 2 pending review, 1 rejected, 1 draft, 1 inactive.');
    }

    /**
     * Small local helper — looks up the category's feature definitions by
     * key and creates the correctly-typed ActivityFeatureValue row for
     * each, mirroring exactly what ActivityFeatureService::sync() does at
     * runtime, so seeded data matches what the real API would produce.
     */
    protected function attachDivingFeatures(Activity $activity, Category $category, array $values): void
    {
        $definitions = FeatureDefinition::where('category_id', $category->id)->get()->keyBy('key');

        foreach ($values as $key => $value) {
            $definition = $definitions->get($key);

            if (! $definition) {
                continue;
            }

            $columns = match ($definition->type) {
                'text', 'select' => ['value_text' => $value],
                'number' => ['value_number' => $value],
                'boolean' => ['value_boolean' => (bool) $value],
                'multi_select' => ['value_json' => $value],
            };

            $activity->featureValues()->create(array_merge(
                ['feature_definition_id' => $definition->id],
                $columns
            ));
        }
    }
}
