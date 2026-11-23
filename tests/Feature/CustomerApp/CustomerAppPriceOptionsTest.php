<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * The PRICE OPTIONS and the RESERVATION TYPE of an activity, on the customer's page and on the provider's: what a booking of each option is made of (persons, units, time or fixed)
 * and what it costs. The scenario activity is per person at 1,500 an adult.
 */
class CustomerAppPriceOptionsTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private const OPTION_KEYS = ['id', 'name', 'description', 'reservation_type', 'booking_model', 'price_unit', 'is_default', 'is_active', 'min_participants', 'max_participants',
        'min_quantity', 'max_quantity', 'min_duration', 'max_duration', 'min_duration_minutes', 'max_duration_minutes', 'time_booking', 'duration_unit', 'prices', 'has_departure_prices'];

    private array $a;

    private string $token;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        // An activity has a city (the provider's page reads it).
        $this->cityId = $this->makeCity()->id;
        $this->a['activity']->update(['city_id' => $this->cityId]);
        [, , $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An activity of its own, booked by `$model` at `$price`: its one option is the default. @return array{0: Activity, 1: ActivityBookingOption} */
    private function activityOf(string $model, float $price, array $optionAttrs = [], string $title = 'Boat'): array
    {
        $activity = $this->moreActivity($this->a, $title, 100, ['city_id' => $this->cityId]);
        $option = $activity->bookingOptions()->first();
        $option->update(['booking_model' => $model, 'is_default' => true] + $optionAttrs);
        $option->pricingRules()->delete();
        $option->pricingRules()->create(['participant_type' => null, 'price' => $price]);

        return [$activity->refresh(), $option->refresh()];
    }

    private function page(Activity $activity): array
    {
        return $this->ca($this->token, 'GET', "/activities/{$activity->id}")->assertOk()->json('data');
    }

    private function providerPage(Activity $activity)
    {
        return $this->hit($this->a['token'], 'GET', "/api/v1/provider/activities/{$activity->uuid}")->assertOk()->json('data');
    }

    // --- The customer's page ----------------------------------------------------------------------------------------------

    public function test_the_page_says_what_the_price_is_for_and_lists_the_price_options_with_their_prices(): void
    {
        // Made in the wrong order (infant, then child, after the adult price): they come out adult, child, infant.
        $this->a['option']->pricingRules()->create(['participant_type' => 'infant', 'price' => 100]);
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 750]);
        $d = $this->page($this->a['activity']);

        $this->assertEquals([1500.0, 'per_person', 'persons'], [$d['price'], $d['price_unit'], $d['reservation_type']]);
        $keys = array_keys($d);
        $this->assertSame(['price', 'price_unit', 'reservation_type', 'price_options', 'original_price'], array_slice($keys, array_search('price', $keys), 5));
        $this->assertCount(1, $d['price_options']);
        $option = $d['price_options'][0];
        $this->assertSame(self::OPTION_KEYS, array_keys($option));
        $this->assertSame([$this->a['option']->id, 'persons', 'per_person', false], [$option['id'], $option['reservation_type'], $option['booking_model'], $option['has_departure_prices']]);
        $this->assertEquals([['adult', 'Adult', 1500.0], ['child', 'Child', 750.0], ['infant', 'Infant', 100.0]], array_map(fn ($p) => [$p['type'], $p['label'], $p['price']], $option['prices']), 'Adult, child, infant');
        $this->assertSame(config('platform.currency'), $option['prices'][0]['currency']);
    }

    public function test_every_reservation_type_says_what_it_is_counted_in_and_what_it_costs(): void
    {
        $cases = [
            ['per_unit', 'units', 'unit', 'Jet Ski', ['min_quantity' => 1, 'max_quantity' => 4]],
            ['per_hour', 'time', 'hour', 'Charter', ['min_duration' => 2, 'max_duration' => 6, 'duration_unit' => 'hour']],
            ['per_day', 'time', 'day', 'Yacht', ['min_duration' => 1, 'max_duration' => 3]],
            ['per_trip', 'fixed', 'trip', 'Private Trip', []],
            ['per_group', 'fixed', 'group', 'Group Tour', []],
            ['fixed', 'fixed', 'fixed', 'Flight', []],
            ['package', 'fixed', 'package', 'Package', []],
        ];

        foreach ($cases as [$model, $type, $priceType, $title, $limits]) {
            [$activity] = $this->activityOf($model, 700, $limits, $title);
            $d = $this->page($activity);
            $option = $d['price_options'][0];

            $this->assertEquals([700.0, $model, $type], [$d['price'], $d['price_unit'], $d['reservation_type']], $model);
            $this->assertSame([$type, $model, $model], [$option['reservation_type'], $option['booking_model'], $option['price_unit']], $model);
            $this->assertEquals([[$priceType, 700.0]], array_map(fn ($p) => [$p['type'], $p['price']], $option['prices']), "{$model}: one price, for no kind of person");
            foreach ($limits as $key => $value) {
                $this->assertSame($value, $option[$key], "{$model} {$key}");
            }
        }
    }

    public function test_the_unit_of_time_is_the_hour_or_the_day_even_when_the_provider_left_it_out(): void
    {
        [$hourly] = $this->activityOf('per_hour', 700, [], 'Hourly');
        [$daily] = $this->activityOf('per_day', 2000, [], 'Daily');

        $this->assertSame('hour', $this->page($hourly)['price_options'][0]['duration_unit']);
        $this->assertSame('day', $this->page($daily)['price_options'][0]['duration_unit']);
        $this->assertNull($this->page($this->a['activity'])['price_options'][0]['duration_unit'], 'Not a booking by time');
    }

    public function test_an_option_the_provider_switched_off_is_not_offered_to_customers(): void
    {
        $off = $this->a['activity']->bookingOptions()->create(['name' => 'Old', 'slug' => 'old-'.uniqid(), 'booking_model' => 'per_person', 'is_active' => false, 'sort_order' => 3]);
        $off->pricingRules()->create(['participant_type' => 'adult', 'price' => 99]);
        $on = $this->a['activity']->bookingOptions()->create(['name' => 'VIP', 'slug' => 'vip-'.uniqid(), 'booking_model' => 'per_person', 'sort_order' => 2]);
        $on->pricingRules()->create(['participant_type' => 'adult', 'price' => 2500]);

        $options = $this->page($this->a['activity'])['price_options'];

        $this->assertSame(['Standard', 'VIP'], array_column($options, 'name'), 'In the provider\'s order, without the one that is off');
        $this->assertEquals([1500.0, 2500.0], array_map(fn ($o) => $o['prices'][0]['price'], $options));
    }

    public function test_a_price_for_one_departure_is_flagged_and_the_prices_listed_are_the_base_ones(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'adult', 'price' => 2000, 'schedule_period_id' => $this->a['period']]);

        $option = $this->page($this->a['activity'])['price_options'][0];

        $this->assertTrue($option['has_departure_prices']);
        $this->assertEquals([1500.0], array_column($option['prices'], 'price'), 'The base price; the exact price of a booking is the summary\'s');
    }

    public function test_the_default_option_is_the_one_the_provider_marked_whatever_its_model(): void
    {
        $hourly = $this->a['activity']->bookingOptions()->create(['name' => 'By the hour', 'slug' => 'hourly-'.uniqid(), 'booking_model' => 'per_hour', 'is_default' => true, 'min_duration' => 1, 'max_duration' => 8, 'sort_order' => 4]);
        $hourly->pricingRules()->create(['participant_type' => null, 'price' => 300]);

        $d = $this->page($this->a['activity']);

        $this->assertEquals([300.0, 'per_hour', 'time'], [$d['price'], $d['price_unit'], $d['reservation_type']]);
        $this->assertSame(['persons', 'time'], array_column($d['price_options'], 'reservation_type'), 'Both are offered');
        $this->assertSame([false, true], array_column($d['price_options'], 'is_default'));
    }

    // --- The lists ---------------------------------------------------------------------------------------------------

    public function test_a_card_says_what_its_price_is_for(): void
    {
        $this->activityOf('per_hour', 700, ['min_duration' => 1], 'Charter');

        $cards = collect($this->ca(null, 'GET', '/activities')->assertOk()->json('data'))->keyBy('title');

        $this->assertEquals([1500.0, 'per_person', 'persons'], [$cards['Reef Dive']['price'], $cards['Reef Dive']['price_unit'], $cards['Reef Dive']['reservation_type']]);
        $this->assertEquals([700.0, 'per_hour', 'time'], [$cards['Charter']['price'], $cards['Charter']['price_unit'], $cards['Charter']['reservation_type']]);
        $this->assertArrayNotHasKey('price_options', $cards['Charter'], 'The list is cards; the options are on the page');
    }

    public function test_activities_of_every_booking_model_are_listed_and_found_and_ordered_by_their_price(): void
    {
        $this->activityOf('per_unit', 300, ['min_quantity' => 1], 'Kayak');
        $this->activityOf('per_hour', 700, ['min_duration' => 1], 'Charter');
        $this->activityOf('fixed', 5000, [], 'Flight');

        $all = array_column($this->ca(null, 'GET', '/activities', ['sort' => 'price_asc'])->assertOk()->json('data'), 'title');
        $between = array_column($this->ca(null, 'GET', '/activities', ['min_price' => 250, 'max_price' => 800, 'sort' => 'price_asc'])->json('data'), 'title');

        $this->assertSame(['Kayak', 'Charter', 'Reef Dive', 'Flight'], $all);
        $this->assertSame(['Kayak', 'Charter'], $between);
    }

    // --- The provider's page -------------------------------------------------------------------------------------------

    public function test_the_provider_page_has_the_reservation_type_and_the_same_price_options_with_the_switched_off_ones_marked(): void
    {
        $off = $this->a['activity']->bookingOptions()->create(['name' => 'Old', 'slug' => 'old-'.uniqid(), 'booking_model' => 'per_person', 'is_active' => false, 'sort_order' => 3]);
        $off->pricingRules()->create(['participant_type' => 'adult', 'price' => 99]);
        $mine = $this->providerPage($this->a['activity']);

        $keys = array_keys($mine);
        $this->assertSame(['price', 'price_unit', 'reservation_type'], array_slice($keys, array_search('price', $keys), 3));
        $this->assertSame(['persons', 'per_person'], [$mine['reservation_type'], $mine['price_unit']]);
        $this->assertSame(['Standard', 'Old'], array_column($mine['price_options'], 'name'));
        $this->assertSame([true, false], array_column($mine['price_options'], 'is_active'));
        $this->assertSame($this->page($this->a['activity'])['price_options'][0], $mine['price_options'][0], 'The same words on both pages');
        $this->assertArrayHasKey('booking_options', $mine, 'What was there stays');
    }

    public function test_an_activity_with_no_city_has_a_page_with_no_city_and_not_an_error(): void
    {
        $activity = $this->moreActivity($this->a, 'No City Yet', 100);   // made through the API there is no city until one is set

        $mine = $this->providerPage($activity);

        $this->assertNull($mine['city']);
        $this->assertSame('persons', $mine['reservation_type']);
        $this->assertSame('Jeddah', $this->providerPage($this->a['activity'])['city']['name_en'], 'An activity that has a city is as it was');
    }

    public function test_the_provider_page_says_time_for_a_booking_by_the_hour(): void
    {
        [$activity] = $this->activityOf('per_hour', 700, ['min_duration' => 2, 'max_duration' => 6], 'Charter');

        $mine = $this->providerPage($activity);

        $this->assertSame(['per_hour', 'time'], [$mine['price_unit'], $mine['reservation_type']]);
        $this->assertSame('time', $mine['price_options'][0]['reservation_type']);
    }

    public function test_the_providers_list_stays_light_and_what_they_save_comes_back_with_the_options(): void
    {
        $list = $this->hit($this->a['token'], 'GET', '/api/v1/provider/activities')->assertOk()->json('data');
        $row = collect($list['data'] ?? $list)->first();
        $this->assertArrayNotHasKey('price_options', $row, 'A block of options for every activity of a page is not what a list is for');

        $saved = $this->hit($this->a['token'], 'PATCH', "/api/v1/provider/activities/{$this->a['activity']->uuid}", ['title' => 'Reef Dive Deluxe'])->assertOk()->json('data');
        $this->assertSame('Reef Dive Deluxe', $saved['title']);
        $this->assertArrayHasKey('price_options', $saved);
        $this->assertSame('persons', $saved['reservation_type']);
    }
}
