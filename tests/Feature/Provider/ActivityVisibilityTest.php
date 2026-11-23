<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\City;
use App\Services\ActivityVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * WHY customers can or cannot see an activity: every answer about an activity the provider reads or saves carries `customer_visibility` (`is_visible`, `is_bookable` and the
 * `problems`, each with what to do), the list says `visible_to_customers`, and the messages of creating and publishing say so. It must always AGREE with what the customer app
 * does: an activity is visible to customers exactly when `is_visible` says so.
 */
class ActivityVisibilityTest extends TestCase
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

    private function url(?Activity $a = null, string $tail = ''): string
    {
        return '/api/v1/provider/activities'.($a ? "/{$a->uuid}" : '').$tail;
    }

    private function visibility(Activity $a): array
    {
        return $this->hit($this->p['token'], 'GET', $this->url($a))->assertOk()->json('data.customer_visibility');
    }

    private function codes(array $visibility): array
    {
        return array_column($visibility['problems'], 'code');
    }

    private function customerSees(Activity $a): bool
    {
        $this->app['auth']->forgetGuards();
        $found = $this->getJson("/api/v1/customer-app/activities/{$a->id}")->status() === 200;
        $this->app['auth']->forgetGuards();
        $inSearch = in_array($a->title, array_column($this->getJson('/api/v1/customer-app/activities?per_page=50')->json('data') ?? [], 'title'), true);
        $this->assertSame($found, $inSearch, 'What customers can open is what search lists');

        return $found;
    }

    /** An activity of this provider made through the API with only the basics: published, and no option, no price, no schedule. */
    private function basics(string $title = 'Sunset Boat Trip'): Activity
    {
        $r = $this->hit($this->p['token'], 'POST', $this->url(), ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->cityId, 'title' => $title, 'max_participants' => 10])->assertCreated();

        return Activity::findOrFail($r->json('data.id'));
    }

    // --- A complete activity ---------------------------------------------------------------------------------------

    public function test_a_complete_activity_is_visible_and_bookable_and_has_no_problems(): void
    {
        $v = $this->visibility($this->p['activity']);

        $this->assertSame([true, true, []], [$v['is_visible'], $v['is_bookable'], $v['problems']]);
        $this->assertTrue($this->customerSees($this->p['activity']));
    }

    // --- Made through the API ------------------------------------------------------------------------------------------

    public function test_an_activity_made_with_only_the_basics_is_published_but_hidden_and_says_why(): void
    {
        $r = $this->hit($this->p['token'], 'POST', $this->url(), ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->cityId, 'title' => 'Sunset Boat Trip'])->assertCreated();
        $a = Activity::findOrFail($r->json('data.id'));
        $v = $r->json('data.customer_visibility');

        $this->assertSame('published', $r->json('data.status'));
        $this->assertSame([false, false, ['no_booking_option', 'no_schedule']], [$v['is_visible'], $v['is_bookable'], $this->codes($v)]);
        $this->assertSame(['visibility', 'booking'], array_column($v['problems'], 'blocks'));
        $this->assertStringContainsString('Customers cannot see it yet: It has no booking option', $r->json('message'));
        $this->assertFalse($r->json('data.visible_to_customers'));
        $this->assertFalse($this->customerSees($a));
    }

    public function test_an_option_with_no_price_hides_the_activity_until_it_has_an_adult_price(): void
    {
        $a = $this->basics();
        $option = $this->hit($this->p['token'], 'POST', $this->url($a, '/booking-options'), ['name' => 'Standard', 'booking_model' => 'per_person', 'is_default' => true])->assertCreated();
        $optionUuid = $option->json('data.uuid');

        $v = $this->visibility($a);
        $this->assertContains('no_price', $this->codes($v));
        $this->assertNotContains('no_booking_option', $this->codes($v));
        $this->assertStringContainsString('"Standard" has no adult price', collect($v['problems'])->firstWhere('code', 'no_price')['message']);
        $this->assertFalse($this->customerSees($a));

        // A child price alone is not what customers are shown: a booking by persons is priced by its adult price.
        $this->hit($this->p['token'], 'POST', $this->url($a, "/booking-options/{$optionUuid}/pricing-rules"), ['participant_type' => 'child', 'price' => 150])->assertCreated();
        $this->assertContains('no_price', $this->codes($this->visibility($a)));
        $this->assertFalse($this->customerSees($a));

        $this->hit($this->p['token'], 'POST', $this->url($a, "/booking-options/{$optionUuid}/pricing-rules"), ['participant_type' => 'adult', 'price' => 300])->assertCreated();
        $v = $this->visibility($a);
        $this->assertTrue($v['is_visible']);
        $this->assertSame(['no_schedule'], $this->codes($v), 'Visible, but nothing can be booked: it has no schedule yet');
        $this->assertFalse($v['is_bookable']);
        $this->assertTrue($this->customerSees($a));
    }

    public function test_an_activity_by_the_hour_needs_one_price_not_an_adult_one(): void
    {
        $a = $this->basics();
        $option = $this->hit($this->p['token'], 'POST', $this->url($a, '/booking-options'), ['name' => 'By the hour', 'booking_model' => 'per_hour', 'is_default' => true, 'min_duration' => 1, 'max_duration' => 8])->assertCreated();
        $this->assertContains('no_price', $this->codes($this->visibility($a)));
        $this->assertStringContainsString('has no price', collect($this->visibility($a)['problems'])->firstWhere('code', 'no_price')['message']);

        $this->hit($this->p['token'], 'POST', $this->url($a, "/booking-options/{$option->json('data.uuid')}/pricing-rules"), ['price' => 700])->assertCreated();

        $this->assertTrue($this->visibility($a)['is_visible']);
        $this->assertTrue($this->customerSees($a));
    }

    public function test_everything_in_one_request_is_visible_at_once_and_the_message_says_nothing_is_wrong(): void
    {
        $r = $this->hit($this->p['token'], 'POST', $this->url(), ['category_id' => $this->p['activity']->category_id, 'city_id' => $this->cityId, 'title' => 'Everything At Once',
            'schedule' => [['day_of_week' => 'saturday', 'start_time' => '09:00', 'end_time' => '13:00']],
            'booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person', 'is_default' => true, 'pricing_rules' => [['participant_type' => 'adult', 'price' => 300], ['participant_type' => 'child', 'price' => 150]]]]])->assertCreated();

        $this->assertSame('Activity created successfully.', $r->json('message'));
        $this->assertSame([true, true, []], [$r->json('data.customer_visibility.is_visible'), $r->json('data.customer_visibility.is_bookable'), $r->json('data.customer_visibility.problems')]);
        $this->assertTrue($r->json('data.visible_to_customers'));
        $this->assertTrue($this->customerSees(Activity::findOrFail($r->json('data.id'))));
    }

    // --- Not live -------------------------------------------------------------------------------------------------------

    public function test_a_paused_a_ready_a_draft_and_a_suspended_activity_each_say_what_it_is(): void
    {
        $a = $this->p['activity'];
        $cases = [
            ['paused', ['status' => 'inactive'], 'You paused this activity'],
            ['ready_not_activated', ['status' => 'approved'], 'ready but hidden until you activate it'],
            ['draft', ['status' => 'draft'], 'It is a draft. Publish it when it is ready.'],
            ['suspended', ['status' => 'inactive', 'suspended_at' => now(), 'suspension_reason' => 'Safety complaint'], 'Safety complaint'],
        ];

        foreach ($cases as [$code, $state, $text]) {
            $a->forceFill($state + ['suspended_at' => null, 'suspension_reason' => null])->save();
            $a->forceFill($state)->save();

            $v = $this->visibility($a->refresh());

            $this->assertSame([false, [$code]], [$v['is_visible'], $this->codes($v)], $code);
            $this->assertStringContainsString($text, $v['problems'][0]['message'], $code);
            $this->assertFalse($this->customerSees($a), $code);
            $this->assertFalse($this->hit($this->p['token'], 'GET', $this->url())->json('data.0.visible_to_customers') ?? false);
        }

        $a->forceFill(['status' => 'published', 'suspended_at' => null, 'suspension_reason' => null])->save();
        $this->assertTrue($this->customerSees($a), 'Live again');
    }

    public function test_a_draft_is_explained_by_the_provider_when_it_is_not_approved_and_by_the_setting_otherwise(): void
    {
        $a = $this->p['activity'];
        $a->update(['status' => 'draft']);

        $this->p['provider']->update(['verification_status' => 'pending']);
        $this->assertSame(['provider_not_verified'], $this->codes($this->hitDetailAsDirect($a)));

        $this->p['provider']->update(['verification_status' => 'verified']);
        config(['activities.auto_publish' => false]);
        $v = $this->visibility($a);
        $this->assertSame(['draft'], $this->codes($v));
        $this->assertStringContainsString('not published automatically', $v['problems'][0]['message']);
    }

    /** The visibility of an activity worked out directly (a provider that is not verified cannot call the provider app, so the service is asked). */
    private function hitDetailAsDirect(Activity $a): array
    {
        return app(ActivityVisibility::class)->of($a->refresh());
    }

    // --- The list and the messages --------------------------------------------------------------------------------------

    public function test_the_list_says_for_every_activity_whether_customers_can_see_it(): void
    {
        $hidden = $this->basics('Hidden One');

        $rows = collect($this->hit($this->p['token'], 'GET', $this->url().'?per_page=50')->assertOk()->json('data'))->keyBy('title');

        $this->assertTrue($rows[$this->p['activity']->title]['visible_to_customers']);
        $this->assertFalse($rows['Hidden One']['visible_to_customers']);
        $this->assertArrayNotHasKey('customer_visibility', $rows['Hidden One'], 'The list stays light: the page has the reasons');
    }

    public function test_publishing_an_activity_that_customers_still_cannot_see_says_so(): void
    {
        $a = $this->basics();

        $r = $this->hit($this->p['token'], 'POST', $this->url($a, '/publish'))->assertOk();

        $this->assertStringContainsString('Activity published.', $r->json('message'));
        $this->assertStringContainsString('Customers cannot see it yet: It has no booking option', $r->json('message'));
        $this->assertFalse($r->json('data.customer_visibility.is_visible'));
    }

    public function test_updating_an_activity_keeps_answering_with_the_visibility(): void
    {
        $a = $this->basics();

        $r = $this->hit($this->p['token'], 'PATCH', $this->url($a), ['city_id' => $this->cityId, 'title' => 'Renamed'])->assertOk();

        $this->assertFalse($r->json('data.customer_visibility.is_visible'));
        $this->assertSame(['no_booking_option', 'no_schedule'], array_column($r->json('data.customer_visibility.problems'), 'code'));
    }
}
