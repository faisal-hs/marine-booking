<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * What a provider sets for a booking option booked by TIME: the shortest booking in MINUTES (which is also the block it grows by), the longest (optional), and the price of one block,
 * with the operating period (which may run past midnight) as the schedule. An option with none of them is what it always was: whole hours.
 */
class ProviderTimeBookingConfigTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $p;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->approvedProvider();
        $this->cityId = City::firstOrCreate(['slug' => 'dahab'], ['name_en' => 'Dahab', 'name_ar' => 'دهب', 'is_active' => true])->id;
        $this->p['activity']->update(['city_id' => $this->cityId]);
    }

    private function optionsUrl(?Activity $a = null, string $tail = ''): string
    {
        return '/api/v1/provider/activities/'.($a ?? $this->p['activity'])->uuid.'/booking-options'.$tail;
    }

    private function make(array $over = [])
    {
        return $this->hit($this->p['token'], 'POST', $this->optionsUrl(), $over + ['name' => 'By time', 'booking_model' => 'per_hour', 'min_duration_minutes' => 30, 'max_duration_minutes' => 240]);
    }

    private function createActivity(array $over = [])
    {
        return $this->hit($this->p['token'], 'POST', '/api/v1/provider/activities', $over + ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->cityId, 'title' => 'Night Boat Rental']);
    }

    private function timedOption(array $over = []): array
    {
        return $over + ['name' => 'By time', 'booking_model' => 'per_hour', 'is_default' => true, 'min_duration_minutes' => 30, 'max_duration_minutes' => 240, 'pricing_rules' => [['price' => 200]]];
    }

    // --- Configuring an option ----------------------------------------------------------------------------------------------

    public function test_an_option_by_time_is_set_in_minutes_without_the_hours(): void
    {
        $r = $this->make()->assertCreated();

        $this->assertSame([30, 240, ['unit' => 30, 'min' => 30, 'max' => 240]], [$r->json('data.min_duration_minutes'), $r->json('data.max_duration_minutes'), $r->json('data.time_booking')]);
        $this->assertNull($r->json('data.min_duration'), 'No hours needed');
    }

    public function test_the_longest_is_optional(): void
    {
        $r = $this->make(['max_duration_minutes' => null])->assertCreated();

        $this->assertSame(['unit' => 30, 'min' => 30, 'max' => null], $r->json('data.time_booking'));
    }

    public function test_an_option_by_the_hour_without_minutes_still_needs_its_hours(): void
    {
        $this->hit($this->p['token'], 'POST', $this->optionsUrl(), ['name' => 'Hourly', 'booking_model' => 'per_hour'])->assertStatus(422)->assertJsonValidationErrors(['min_duration', 'max_duration']);

        $r = $this->hit($this->p['token'], 'POST', $this->optionsUrl(), ['name' => 'Hourly', 'booking_model' => 'per_hour', 'min_duration' => 2, 'max_duration' => 8])->assertCreated();
        $this->assertSame(['unit' => 60, 'min' => 120, 'max' => 480], $r->json('data.time_booking'), 'As it always was: blocks of an hour, two hours at least');
        $this->assertNull($r->json('data.min_duration_minutes'));
    }

    public function test_the_minutes_must_be_sensible(): void
    {
        $this->make(['min_duration_minutes' => 0])->assertStatus(422)->assertJsonValidationErrors('min_duration_minutes');
        $this->make(['min_duration_minutes' => 2000])->assertStatus(422)->assertJsonValidationErrors('min_duration_minutes');
        $this->make(['min_duration_minutes' => 'half an hour'])->assertStatus(422)->assertJsonValidationErrors('min_duration_minutes');
        $this->make(['max_duration_minutes' => 20])->assertStatus(422)->assertJsonValidationErrors('max_duration_minutes');                  // shorter than the shortest
        $this->make(['max_duration_minutes' => 100])->assertStatus(422)->assertJsonPath('errors.max_duration_minutes.0', 'The longest booking must be a whole number of 30-minute blocks.');
        $this->make(['min_duration_minutes' => null, 'max_duration_minutes' => 120, 'min_duration' => 1, 'max_duration' => 4])->assertStatus(422)->assertJsonValidationErrors('min_duration_minutes');
    }

    public function test_minutes_are_for_an_option_booked_by_the_hour_only(): void
    {
        foreach (['per_person', 'per_group', 'per_trip', 'per_unit', 'per_day', 'fixed', 'package'] as $model) {
            $this->hit($this->p['token'], 'POST', $this->optionsUrl(), ['name' => "Not {$model}", 'booking_model' => $model, 'min_duration' => 1, 'max_duration' => 2, 'min_duration_minutes' => 30])
                ->assertStatus(422)->assertJsonValidationErrors('min_duration_minutes');
        }
    }

    public function test_the_block_can_be_changed_and_the_longest_is_held_to_it_even_when_only_one_is_sent(): void
    {
        $uuid = $this->make()->assertCreated()->json('data.uuid');
        $url = $this->optionsUrl(null, "/{$uuid}");

        $this->hit($this->p['token'], 'PATCH', $url, ['min_duration_minutes' => 60, 'max_duration_minutes' => 240])->assertOk()->assertJsonPath('data.time_booking.unit', 60);
        // Only the longest is sent: it is held to the block the option has (60), not to nothing.
        $this->hit($this->p['token'], 'PATCH', $url, ['max_duration_minutes' => 150])->assertStatus(422)->assertJsonPath('errors.max_duration_minutes.0', 'The longest booking must be a whole number of 60-minute blocks.');
        $this->hit($this->p['token'], 'PATCH', $url, ['max_duration_minutes' => 180])->assertOk()->assertJsonPath('data.max_duration_minutes', 180);
        // Only the block is sent: the longest the option already has must still be whole blocks of it.
        $this->hit($this->p['token'], 'PATCH', $url, ['min_duration_minutes' => 50])->assertStatus(422)->assertJsonValidationErrors('max_duration_minutes');
        $this->hit($this->p['token'], 'PATCH', $url, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    }

    public function test_the_minutes_can_be_taken_off_to_go_back_to_hours(): void
    {
        $uuid = $this->make()->assertCreated()->json('data.uuid');

        $r = $this->hit($this->p['token'], 'PATCH', $this->optionsUrl(null, "/{$uuid}"), ['min_duration_minutes' => null, 'max_duration_minutes' => null, 'min_duration' => 1, 'max_duration' => 4])->assertOk();

        $this->assertSame(['unit' => 60, 'min' => 60, 'max' => 240], $r->json('data.time_booking'));
    }

    // --- In one request ------------------------------------------------------------------------------------------------------

    public function test_everything_for_a_booking_by_time_is_made_in_one_request(): void
    {
        $r = $this->createActivity(['schedule' => [['day_of_week' => 'friday', 'start_time' => '20:00', 'end_time' => '05:00']], 'booking_options' => [$this->timedOption()]])->assertCreated();

        $this->assertSame([30, 240], [$r->json('data.booking_options.0.min_duration_minutes'), $r->json('data.booking_options.0.max_duration_minutes')]);
        $this->assertSame([['friday', '20:00', '05:00', true]], array_map(fn ($s) => [$s['day_of_week'], $s['start_time'], $s['end_time'], $s['crosses_midnight']], $r->json('data.schedule')));
        $this->assertSame([true, true], [$r->json('data.customer_visibility.is_visible'), $r->json('data.customer_visibility.is_bookable')]);
    }

    public function test_the_nested_option_is_checked_like_the_single_one(): void
    {
        $this->createActivity(['booking_options' => [$this->timedOption(['max_duration_minutes' => 100])]])->assertStatus(422)->assertJsonValidationErrors('booking_options.0.max_duration_minutes');
        $this->createActivity(['booking_options' => [$this->timedOption(['min_duration_minutes' => null])]])->assertStatus(422)->assertJsonValidationErrors('booking_options.0.min_duration');
        $this->createActivity(['booking_options' => [['name' => 'Per person', 'booking_model' => 'per_person', 'min_duration_minutes' => 30, 'pricing_rules' => [['participant_type' => 'adult', 'price' => 100]]]]])
            ->assertStatus(422)->assertJsonValidationErrors('booking_options.0.min_duration_minutes');
        $this->createActivity(['booking_options' => [$this->timedOption(['min_duration_minutes' => null, 'max_duration_minutes' => 120, 'min_duration' => 1, 'max_duration' => 4])]])->assertStatus(422)->assertJsonValidationErrors('booking_options.0.min_duration_minutes');
        $this->createActivity(['booking_options' => [$this->timedOption(['min_duration_minutes' => null, 'max_duration_minutes' => null, 'min_duration' => 1, 'max_duration' => 4])]])->assertCreated();   // in hours, as ever
    }

    public function test_a_combined_update_replaces_the_options_with_the_new_minutes(): void
    {
        $a = $this->p['activity'];

        $r = $this->hit($this->p['token'], 'PUT', "/api/v1/provider/activities/{$a->uuid}", ['city_id' => $this->cityId, 'booking_options' => [$this->timedOption(['min_duration_minutes' => 45, 'max_duration_minutes' => 180, 'pricing_rules' => [['price' => 450]]])]])->assertOk();

        $this->assertSame([45, 180], [$r->json('data.booking_options.0.min_duration_minutes'), $r->json('data.booking_options.0.max_duration_minutes')]);
    }

    // --- What the provider reads back ----------------------------------------------------------------------------------------

    public function test_the_provider_sees_the_price_of_a_block_and_what_the_block_is(): void
    {
        $r = $this->createActivity(['schedule' => [['day_of_week' => 'friday', 'start_time' => '20:00', 'end_time' => '05:00']], 'booking_options' => [$this->timedOption()]])->assertCreated();
        $option = collect($this->hit($this->p['token'], 'GET', '/api/v1/provider/activities/'.$r->json('data.uuid'))->assertOk()->json('data.price_options'))->first();

        $this->assertSame(['time', 'per_30_minutes', 30, 240], [$option['reservation_type'], $option['price_unit'], $option['time_booking']['unit'], $option['time_booking']['max']]);
        $this->assertSame(['30_minutes', '30 minutes', 200.0], [$option['prices'][0]['type'], $option['prices'][0]['label'], (float) $option['prices'][0]['price']]);
    }

    public function test_an_option_by_time_without_a_price_is_hidden_and_the_provider_is_told(): void
    {
        $r = $this->createActivity(['schedule' => [['day_of_week' => 'friday', 'start_time' => '20:00', 'end_time' => '05:00']], 'booking_options' => [$this->timedOption(['pricing_rules' => []])]])->assertCreated();

        $this->assertFalse($r->json('data.customer_visibility.is_visible'));
        $this->assertSame(['no_price'], array_column($r->json('data.customer_visibility.problems'), 'code'));
        $this->assertStringContainsString('has no price', $r->json('data.customer_visibility.problems.0.message'));
    }

    // --- Operating periods that cross midnight ---------------------------------------------------------------------------------

    public function test_a_period_from_eight_pm_to_five_am_is_accepted_and_is_one_period(): void
    {
        $a = $this->p['activity'];

        $r = $this->hit($this->p['token'], 'POST', "/api/v1/provider/activities/{$a->uuid}/schedule", ['day_of_week' => 'friday', 'start_time' => '20:00', 'end_time' => '05:00'])->assertCreated();

        $this->assertSame([true, '20:00', '05:00'], [$r->json('data.crosses_midnight'), $r->json('data.start_time'), $r->json('data.end_time')]);
        $this->assertSame(1, $a->schedulePeriods()->where('day_of_week', 5)->count());
    }

    public function test_a_period_of_no_length_is_still_refused_and_a_day_period_is_not_overnight(): void
    {
        $a = $this->p['activity'];

        $this->hit($this->p['token'], 'POST', "/api/v1/provider/activities/{$a->uuid}/schedule", ['day_of_week' => 'friday', 'start_time' => '22:00', 'end_time' => '22:00'])->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->hit($this->p['token'], 'POST', "/api/v1/provider/activities/{$a->uuid}/schedule", ['day_of_week' => 'sunday', 'start_time' => '08:00', 'end_time' => '17:00'])->assertCreated()->assertJsonPath('data.crosses_midnight', false);
    }

    public function test_a_period_can_be_changed_to_run_overnight(): void
    {
        $a = $this->p['activity'];
        $id = $this->hit($this->p['token'], 'POST', "/api/v1/provider/activities/{$a->uuid}/schedule", ['day_of_week' => 'sunday', 'start_time' => '08:00', 'end_time' => '17:00'])->assertCreated()->json('data.uuid');

        $this->hit($this->p['token'], 'PATCH', "/api/v1/provider/activities/{$a->uuid}/schedule/{$id}", ['start_time' => '20:00', 'end_time' => '05:00'])->assertOk()->assertJsonPath('data.crosses_midnight', true);
    }
}
