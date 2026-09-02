<?php

namespace Tests\Feature\Seeders;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivityPricingRule;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\FeatureDefinitionSeeder;
use Database\Seeders\ProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityBookingOptionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFullChain(): void
    {
        $this->seed(ProviderSeeder::class);
        $this->seed(CustomerSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(FeatureDefinitionSeeder::class);
        $this->seed(ActivitySeeder::class);
    }

    public function test_every_activity_has_at_least_one_booking_option(): void
    {
        $this->seedFullChain();

        $activitiesWithoutOptions = Activity::doesntHave('bookingOptions')->count();

        $this->assertEquals(0, $activitiesWithoutOptions);
    }

    public function test_every_booking_option_has_at_least_one_pricing_rule(): void
    {
        $this->seedFullChain();

        $optionsWithoutRules = ActivityBookingOption::doesntHave('pricingRules')->count();

        $this->assertEquals(0, $optionsWithoutRules);
    }

    public function test_diving_activities_get_per_person_booking_options(): void
    {
        $this->seedFullChain();

        $diving = Activity::whereHas('category', fn($q) => $q->where('slug', 'diving'))->first();

        $this->assertNotNull($diving);
        $option = $diving->bookingOptions()->first();
        $this->assertEquals('per_person', $option->booking_model->value);
        $this->assertTrue($option->pricingRules()->where('participant_type', 'adult')->exists());
        $this->assertTrue($option->pricingRules()->where('participant_type', 'child')->exists());
    }

    public function test_jet_ski_activities_get_per_unit_booking_options(): void
    {
        $this->seedFullChain();

        $jetSki = Activity::whereHas('category', fn($q) => $q->where('slug', 'jet-ski'))->first();

        $this->assertNotNull($jetSki);
        $option = $jetSki->bookingOptions()->first();
        $this->assertEquals('per_unit', $option->booking_model->value);
        $this->assertNotNull($option->max_quantity);
    }

    public function test_yacht_trips_get_multiple_booking_models_including_package(): void
    {
        $this->seedFullChain();

        $yacht = Activity::whereHas('category', fn($q) => $q->where('slug', 'yacht-trips'))->first();

        $this->assertNotNull($yacht);
        $models = $yacht->bookingOptions->pluck('booking_model')->map(fn($m) => $m->value)->all();

        $this->assertContains('per_hour', $models);
        $this->assertContains('per_trip', $models);
        $this->assertContains('package', $models);

        $perHourOption = $yacht->bookingOptions->firstWhere('booking_model', \App\Enums\BookingModel::PerHour);
        $this->assertNotNull($perHourOption->min_duration);
        $this->assertNotNull($perHourOption->max_duration);
    }

    public function test_exactly_one_default_booking_option_per_activity(): void
    {
        $this->seedFullChain();

        $activity = Activity::has('bookingOptions', '>', 1)->first();
        $this->assertNotNull($activity, 'Expected at least one activity with multiple booking options.');

        $defaultCount = $activity->bookingOptions()->where('is_default', true)->count();
        $this->assertEquals(1, $defaultCount);
    }

    public function test_pricing_rules_have_realistic_varied_prices(): void
    {
        $this->seedFullChain();

        $distinctPrices = ActivityPricingRule::distinct()->pluck('price')->count();

        $this->assertGreaterThan(10, $distinctPrices);
    }

    public function test_pricing_service_can_quote_a_seeded_per_person_option(): void
    {
        $this->seedFullChain();

        $diving = Activity::whereHas('category', fn($q) => $q->where('slug', 'diving'))->first();
        $option = $diving->bookingOptions()->first();

        $quote = app(\App\Services\ActivityPricingService::class)->quote($diving, $option, participants: ['adult' => 2, 'child' => 1]);

        $this->assertGreaterThan(0, $quote['total']);
        $this->assertCount(2, $quote['breakdown']);
    }
}
