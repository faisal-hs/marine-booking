<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/activities: what each activity card says (price, bookings, views, revenue, rating) and the tabs (all, draft, active, paused) with their counts.
 * The provider's commission is 10%; today is Sunday 15 November 2026.
 */
class ProviderActivityCardsTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);   // "Reef Dive": published, 1,500 for an adult
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function list(string $query = '', ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/activities'.$query);
    }

    private function card(?int $id = null, ?array $as = null): ?array
    {
        return collect($this->list('?per_page=100', $as)->assertOk()->json('data'))->firstWhere('id', $id ?? $this->a['activity']->id);
    }

    /** @return array<string, mixed> a second activity of the provider, as a scenario the money helpers can use */
    private function another(string $title, string $status = 'published', float $price = 800): array
    {
        [$activity, $option] = $this->makeBookableActivity($this->a['provider'], $status, ['title' => $title]);
        $option->pricingRules()->update(['price' => $price]);

        return array_merge($this->a, ['activity' => $activity, 'option' => $option, 'period' => $activity->schedulePeriods()->value('id')]);
    }

    // --- The card -----------------------------------------------------------------------------------------------------

    public function test_every_activity_has_the_new_card_fields_beside_the_ones_it_always_had(): void
    {
        $card = $this->card();

        foreach (['id', 'uuid', 'title', 'status', 'suspended', 'price', 'price_unit', 'bookings_count', 'views_count', 'revenue', 'rating', 'reviews_count', 'allowed_payment_methods', 'images', 'booking_options'] as $field) {
            $this->assertArrayHasKey($field, $card, $field);
        }
        $this->assertSame([$this->a['activity']->id, 'Reef Dive', 'published', false], [$card['id'], $card['title'], $card['status'], $card['suspended']]);
    }

    public function test_the_price_is_the_adult_price_of_the_default_option_and_says_how_it_is_charged(): void
    {
        $card = $this->card();

        $this->assertSame([1500, 'per_person'], [$card['price'], $card['price_unit']]);

        $this->a['option']->pricingRules()->create(['participant_type' => 'adult', 'price' => 1200]);
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 300]);
        $this->a['option']->pricingRules()->create(['participant_type' => 'adult', 'price' => 50, 'is_active' => false]);
        $this->assertSame(1200, $this->card()['price'], 'The lowest ACTIVE ADULT price: not the child price, not a switched-off one');
    }

    public function test_the_default_option_is_the_one_marked_default_and_an_inactive_one_is_ignored(): void
    {
        $private = $this->a['activity']->bookingOptions()->create(['name' => 'Private', 'slug' => 'private-'.uniqid(), 'booking_model' => 'per_group']);
        $private->pricingRules()->create(['participant_type' => 'adult', 'price' => 4000]);

        $this->assertSame([1500, 'per_person'], [$this->card()['price'], $this->card()['price_unit']], 'Neither is marked default: the first active one');

        $private->update(['is_default' => true]);
        $this->assertSame([4000, 'per_group'], [$this->card()['price'], $this->card()['price_unit']], 'The one marked default, charged per group');

        $private->update(['is_active' => false]);
        $this->assertSame([1500, 'per_person'], [$this->card()['price'], $this->card()['price_unit']], 'A switched-off option does not count');
    }

    public function test_an_activity_with_nothing_priced_has_a_null_price_and_unit(): void
    {
        $this->a['option']->pricingRules()->delete();
        $card = $this->card();
        $this->assertSame([null, 'per_person'], [$card['price'], $card['price_unit']], 'The unit is how the option charges, the price needs a rule');

        $draft = $this->makeActivity($this->a['provider'], 'draft');
        $bare = $this->card($draft->id);
        $this->assertSame([null, null], [$bare['price'], $bare['price_unit']]);
    }

    public function test_bookings_count_is_the_confirmed_and_completed_ones_of_that_activity(): void
    {
        $customer = $this->customerNamed('Fatima');
        $other = $this->another('Sunset Cruise');
        foreach (['confirmed', 'completed', 'completed', 'pending', 'cancelled', 'expired'] as $status) {
            $this->makeBooking($this->a, $customer, '2026-11-20', $status);
        }
        $this->makeBooking($other, $customer, '2026-11-20', 'confirmed');

        $this->assertSame([3, 1], [$this->card()['bookings_count'], $this->card($other['activity']->id)['bookings_count']]);
    }

    public function test_views_count_is_every_page_view_of_that_activity(): void
    {
        $other = $this->another('Sunset Cruise');
        foreach ([1, 2, 3] as $i) {
            DB::table('activity_views')->insert(['activity_id' => $this->a['activity']->id, 'visitor_hash' => sha1("v{$i}"), 'viewed_on' => '2026-11-1'.$i, 'source' => 'direct', 'created_at' => now()]);
        }
        DB::table('activity_views')->insert(['activity_id' => $other['activity']->id, 'visitor_hash' => sha1('x'), 'viewed_on' => '2026-11-10', 'source' => 'direct', 'created_at' => now()]);

        $this->assertSame([3, 1], [$this->card()['views_count'], $this->card($other['activity']->id)['views_count']]);
    }

    public function test_revenue_is_the_ledgers_gross_of_the_trips_of_that_activity_only(): void
    {
        $other = $this->another('Sunset Cruise');
        $this->fundWallet($this->a, 1000, '2026-11-10');
        $this->fundWallet($this->a, 2000, '2026-09-01');           // any time: it is for the activity's whole life
        $this->fundWallet($other, 700, '2026-11-10');
        $this->makeBooking($this->a, $this->customerNamed('Not yet'), '2026-11-20', 'confirmed', 9999);   // not a trip that has taken place

        $this->assertEquals([3000, 700], [$this->card()['revenue'], $this->card($other['activity']->id)['revenue']], 'The price (the 10% commission is not taken off), after the ledger is brought up to date');
    }

    public function test_revenue_never_includes_the_platforms_fee_or_vat(): void
    {
        config(['customer_app.service_fee_percent' => 3, 'customer_app.vat_percent' => 15]);
        $id = $this->makeBooking($this->a, $this->customerNamed('Paying'), '2026-11-10', 'completed', 1776.75, ['service_fee' => 45, 'tax' => 231.75]);
        $this->makePayment($id, 1776.75);

        $this->assertEquals(1500, $this->card()['revenue']);
    }

    public function test_the_rating_and_the_review_count_are_the_activitys_own(): void
    {
        $this->a['activity']->update(['average_rating' => 4.7, 'reviews_count' => 18]);

        $card = $this->card();

        $this->assertSame([4.7, 18], [$card['rating'], $card['reviews_count']]);
        $this->assertSame([0, 0], [$this->card($this->another('New One')['activity']->id)['rating'], $this->card($this->another('Newer')['activity']->id)['reviews_count']]);
    }

    public function test_the_same_figures_are_on_the_activity_itself_when_it_is_opened_or_created(): void
    {
        $this->fundWallet($this->a, 1000, '2026-11-10');
        $this->a['activity']->update(['average_rating' => 4.2, 'reviews_count' => 5]);

        $shown = $this->hit($this->a['token'], 'GET', "/api/v1/provider/activities/{$this->a['activity']->uuid}")->assertOk()->json('data');

        $this->assertEquals([1500, 'per_person', 1, 0, 1000, 4.2, 5], [$shown['price'], $shown['price_unit'], $shown['bookings_count'], $shown['views_count'], $shown['revenue'], $shown['rating'], $shown['reviews_count']]);
    }

    // --- Counts and tabs ----------------------------------------------------------------------------------------------------

    private function mixed(): void
    {
        $this->a['activity']->forceFill(['created_at' => now()->subDay()])->save();   // the others are newer than the first one
        $this->another('Draft One', 'draft');
        $this->another('Paused One', 'inactive');
        $this->another('Paused Two', 'inactive');
        $this->another('Ready One', 'approved');
        $this->another('Live Two');
    }

    public function test_the_counts_are_over_all_the_providers_activities_and_sit_beside_the_data(): void
    {
        $this->mixed();

        $r = $this->list()->assertOk();

        $this->assertSame(['all' => 6, 'active' => 2, 'draft' => 1, 'paused' => 3], $r->json('counts'));
        $this->assertSame(['success', 'message', 'data', 'pagination', 'counts'], array_keys($r->json()));
        $this->assertSame(['all', 'active', 'draft', 'paused'], array_keys($r->json('counts')));
    }

    public function test_an_activity_an_admin_stopped_and_one_that_is_ready_to_go_live_are_paused(): void
    {
        $stopped = $this->another('Stopped by an admin');
        $stopped['activity']->update(['status' => 'inactive', 'suspended_at' => now(), 'suspension_reason' => 'Complaint', 'suspended_from_status' => 'published']);
        $this->another('Ready', 'approved');

        $card = $this->card($stopped['activity']->id);
        $this->assertSame(['inactive', true], [$card['status'], $card['suspended']]);
        $this->assertSame(['all' => 3, 'active' => 1, 'draft' => 0, 'paused' => 2], $this->list()->json('counts'));
    }

    public function test_the_counts_do_not_change_with_the_tab(): void
    {
        $this->mixed();
        $counts = $this->list()->json('counts');

        foreach (['all', 'draft', 'active', 'paused'] as $tab) {
            $this->assertSame($counts, $this->list("?filter={$tab}")->json('counts'), $tab);
        }
    }

    public function test_each_tab_shows_its_own_activities_newest_first(): void
    {
        $this->mixed();
        $titles = fn (string $q) => array_column($this->list($q)->assertOk()->json('data'), 'title');

        $this->assertSame(['Live Two', 'Reef Dive'], $titles('?filter=active'), 'Newest first');
        $this->assertEqualsCanonicalizing(['Paused One', 'Paused Two', 'Ready One'], $titles('?filter=paused'));
        $this->assertSame(['Draft One'], $titles('?filter=draft'));
        $this->assertCount(6, $titles('?filter=all'));
        $this->assertCount(6, $titles(''));
        $this->assertCount(6, $titles('?filter='), 'An empty value is no filter');
    }

    public function test_the_older_status_filter_still_works_and_combines_with_a_tab(): void
    {
        $this->mixed();
        $titles = fn (string $q) => array_column($this->list($q)->json('data'), 'title');

        $this->assertEqualsCanonicalizing(['Paused One', 'Paused Two'], $titles('?status=inactive'));
        $this->assertSame(['Ready One'], $titles('?filter=paused&status=approved'));
        $this->assertSame([], $titles('?filter=draft&status=published'), 'Both must hold');
    }

    public function test_a_tab_that_does_not_exist_is_a_validation_error(): void
    {
        foreach (['?filter=live', '?filter=ACTIVE', '?filter=deleted'] as $bad) {
            $this->list($bad)->assertStatus(422);
        }
    }

    public function test_the_tabs_page_like_the_list_and_stay_this_providers(): void
    {
        $other = $this->approvedProvider();
        foreach (range(1, 4) as $i) {
            $this->another("Live {$i}");
        }

        $r = $this->list('?filter=active&per_page=2&current_page=3')->assertOk();

        $this->assertSame([1, 5, 3], [count($r->json('data')), $r->json('pagination.total_items'), $r->json('pagination.current_page')]);
        $this->assertSame(['all' => 1, 'active' => 1, 'draft' => 0, 'paused' => 0], $this->list('', $other)->json('counts'));
        $this->assertNull($this->card($other['activity']->id), 'Not in my list');
    }

    public function test_a_provider_with_no_activities_has_zero_counts_and_an_empty_list(): void
    {
        $this->a['activity']->forceDelete();

        $r = $this->list()->assertOk();

        $this->assertSame([[], ['all' => 0, 'active' => 0, 'draft' => 0, 'paused' => 0]], [$r->json('data'), $r->json('counts')]);
    }

    public function test_a_customer_and_nobody_are_refused(): void
    {
        [$customer] = $this->makeCustomer();

        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/activities')->assertForbidden();
        $this->hit(null, 'GET', '/api/v1/provider/activities')->assertUnauthorized();
    }

    // --- Allowed payment methods ----------------------------------------------------------------------------------------

    private function create(array $over = [])
    {
        return $this->hit($this->a['token'], 'POST', '/api/v1/provider/activities', $over + ['category_id' => Category::first()->id, 'title' => 'Paradise Island Day Trip']);
    }

    public function test_a_new_activity_accepts_neither_tabby_nor_tamara_until_the_provider_says_so(): void
    {
        $r = $this->create()->assertCreated();

        $this->assertSame(['tabby' => false, 'tamara' => false], $r->json('data.allowed_payment_methods'));
        $this->assertSame([false, false], [Activity::latest('id')->first()->allows_tabby, Activity::latest('id')->first()->allows_tamara]);
    }

    public function test_the_provider_can_allow_either_or_both_when_creating(): void
    {
        $both = $this->create(['allowed_payment_methods' => ['tabby' => true, 'tamara' => true]])->assertCreated();
        $one = $this->create(['title' => 'Only Tabby', 'allowed_payment_methods' => ['tabby' => true]])->assertCreated();
        $off = $this->create(['title' => 'Explicitly off', 'allowed_payment_methods' => ['tabby' => false, 'tamara' => false]])->assertCreated();

        $this->assertSame(['tabby' => true, 'tamara' => true], $both->json('data.allowed_payment_methods'));
        $this->assertSame(['tabby' => true, 'tamara' => false], $one->json('data.allowed_payment_methods'));
        $this->assertSame(['tabby' => false, 'tamara' => false], $off->json('data.allowed_payment_methods'));
    }

    public function test_a_form_upload_sends_true_and_false_as_words_and_they_are_understood(): void
    {
        $this->app['auth']->forgetGuards();
        $r = $this->withHeaders(['Authorization' => "Bearer {$this->a['token']}", 'Accept' => 'application/json'])
            ->post('/api/v1/provider/activities', ['category_id' => Category::first()->id, 'title' => 'By form', 'allowed_payment_methods' => ['tabby' => 'true', 'tamara' => 'false']])->assertCreated();

        $this->assertSame(['tabby' => true, 'tamara' => false], $r->json('data.allowed_payment_methods'));
    }

    public function test_only_true_or_false_for_tabby_and_tamara_and_no_other_method_is_accepted(): void
    {
        foreach ([['allowed_payment_methods' => ['tabby' => 'maybe']], ['allowed_payment_methods' => ['tamara' => 5]], ['allowed_payment_methods' => ['paypal' => true]], ['allowed_payment_methods' => 'tabby'],
            ['allowed_payment_methods' => ['tabby' => ['x']]]] as $bad) {
            $this->create($bad)->assertStatus(422)->assertJsonValidationErrors(array_key_exists('paypal', (array) $bad['allowed_payment_methods']) || is_string($bad['allowed_payment_methods']) ? 'allowed_payment_methods' : 'allowed_payment_methods.'.array_key_first($bad['allowed_payment_methods']));
        }
        $this->assertSame(1, Activity::count(), 'Only the setup activity');
    }

    public function test_the_methods_can_be_changed_later_one_at_a_time(): void
    {
        $id = $this->create(['allowed_payment_methods' => ['tabby' => true, 'tamara' => false]])->json('data.uuid');

        $this->hit($this->a['token'], 'PUT', "/api/v1/provider/activities/{$id}", ['allowed_payment_methods' => ['tamara' => true]])->assertOk()
            ->assertJsonPath('data.allowed_payment_methods', ['tabby' => true, 'tamara' => true]);
        $this->hit($this->a['token'], 'PUT', "/api/v1/provider/activities/{$id}", ['allowed_payment_methods' => ['tabby' => false]])->assertOk()
            ->assertJsonPath('data.allowed_payment_methods', ['tabby' => false, 'tamara' => true]);
        $this->hit($this->a['token'], 'PUT', "/api/v1/provider/activities/{$id}", ['title' => 'Renamed'])->assertOk()
            ->assertJsonPath('data.allowed_payment_methods', ['tabby' => false, 'tamara' => true], 'An update that does not mention them leaves them');
    }

    public function test_the_methods_are_in_the_list_too(): void
    {
        $this->a['activity']->update(['allows_tabby' => true]);

        $this->assertSame(['tabby' => true, 'tamara' => false], $this->card()['allowed_payment_methods']);
    }

    public function test_another_providers_activity_cannot_have_its_methods_changed(): void
    {
        $other = $this->approvedProvider();

        $this->hit($this->a['token'], 'PUT', "/api/v1/provider/activities/{$other['activity']->uuid}", ['allowed_payment_methods' => ['tabby' => true]])->assertForbidden();
        $this->assertFalse($other['activity']->refresh()->allows_tabby);
    }
}
