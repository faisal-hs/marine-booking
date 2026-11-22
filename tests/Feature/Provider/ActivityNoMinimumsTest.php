<?php

namespace Tests\Feature\Provider;

use App\Http\Resources\Activity\PublicActivityResource;
use App\Models\Activity;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * An activity has no `min_participants` and no `min_age`: not in what a provider sends to create or update it, not in what is answered when it is read (the provider's page, and the
 * public one). What it keeps is `max_participants`. The booking OPTIONS keep their own minimum of places: that is a rule of a price option, not of the activity.
 */
class ActivityNoMinimumsTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->approvedProvider();
        $this->p['activity']->update(['city_id' => $this->city()->id]);
    }

    private function city(): City
    {
        return City::firstOrCreate(['slug' => 'dahab'], ['name_en' => 'Dahab', 'name_ar' => 'دهب', 'is_active' => true]);
    }

    private function url(?Activity $activity = null): string
    {
        return '/api/v1/provider/activities'.($activity ? "/{$activity->uuid}" : '');
    }

    public function test_reading_an_activity_has_no_minimums(): void
    {
        $d = $this->hit($this->p['token'], 'GET', $this->url($this->p['activity']))->assertOk()->json('data');

        $this->assertArrayNotHasKey('min_participants', $d);
        $this->assertArrayNotHasKey('min_age', $d);
        $this->assertArrayHasKey('max_participants', $d, 'The maximum stays');
    }

    public function test_creating_an_activity_needs_no_minimums_and_keeps_none(): void
    {
        $r = $this->hit($this->p['token'], 'POST', $this->url(), ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->city()->id, 'title' => 'Sunrise Dive', 'max_participants' => 10])->assertCreated();

        $this->assertArrayNotHasKey('min_participants', $r->json('data'));
        $this->assertArrayNotHasKey('min_age', $r->json('data'));
        $this->assertSame(10, $r->json('data.max_participants'));
    }

    public function test_a_maximum_of_zero_is_refused_when_creating_too(): void
    {
        $base = ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->city()->id, 'title' => 'Sunrise Dive'];

        $this->hit($this->p['token'], 'POST', $this->url(), $base + ['max_participants' => 0])->assertStatus(422)->assertJsonValidationErrors('max_participants');
        $this->hit($this->p['token'], 'POST', $this->url(), $base + ['max_participants' => 1])->assertCreated();
    }

    public function test_the_minimums_are_not_taken_even_when_they_are_sent(): void
    {
        $r = $this->hit($this->p['token'], 'POST', $this->url(), ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->city()->id, 'title' => 'Sunrise Dive',
            'min_participants' => 5, 'min_age' => 12, 'max_participants' => 10])->assertCreated();

        $activity = Activity::where('uuid', $r->json('data.uuid'))->firstOrFail();
        $this->assertSame(1, (int) $activity->min_participants, 'The column keeps its default: nothing sets it');
        $this->assertNull($activity->min_age);
        $this->assertArrayNotHasKey('min_age', $r->json('data'));
    }

    public function test_updating_an_activity_neither_takes_nor_shows_them_and_the_maximum_is_only_held_to_one(): void
    {
        $activity = $this->p['activity'];

        $r = $this->hit($this->p['token'], 'PUT', $this->url($activity), ['city_id' => $this->city()->id, 'title' => 'Renamed', 'min_participants' => 9, 'min_age' => 18, 'max_participants' => 12])->assertOk();

        $this->assertSame(['Renamed', 12], [$r->json('data.title'), $r->json('data.max_participants')]);
        $this->assertArrayNotHasKey('min_participants', $r->json('data'));
        $this->assertArrayNotHasKey('min_age', $r->json('data'));
        $this->assertSame([1, null], [(int) $activity->fresh()->min_participants, $activity->fresh()->min_age]);

        // No minimum to compare it with now: only that it is at least one.
        $this->hit($this->p['token'], 'PUT', $this->url($activity), ['city_id' => $this->city()->id, 'max_participants' => 0])->assertStatus(422)->assertJsonValidationErrors('max_participants');
        $this->hit($this->p['token'], 'PUT', $this->url($activity), ['city_id' => $this->city()->id, 'max_participants' => 1])->assertOk();
    }

    public function test_the_public_activity_has_no_minimums_either(): void
    {
        $activity = $this->p['activity']->refresh()->load('city');

        $public = (new PublicActivityResource($activity))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('min_participants', $public);
        $this->assertArrayNotHasKey('min_age', $public);
        $this->assertArrayHasKey('max_participants', $public);
    }

    public function test_a_booking_option_keeps_its_own_minimum_of_places(): void
    {
        $option = $this->p['option'];
        $option->update(['min_participants' => 2]);

        $d = $this->hit($this->p['token'], 'GET', $this->url($this->p['activity']))->assertOk()->json('data');
        $mine = collect($d['price_options'])->firstWhere('id', $option->id);

        $this->assertSame(2, $mine['min_participants'], 'The option still says how few places it takes');
    }
}
