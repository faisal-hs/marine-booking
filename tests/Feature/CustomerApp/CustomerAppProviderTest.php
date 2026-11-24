<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Favorite;
use App\Models\ProviderDocument;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * A provider as anyone sees them, with no token: the page (who they are, how they have done, what customers say, what they offer), all their reviews and all their activities, by the
 * provider's id. Everything is worked out from real data: what the platform does not hold is empty, not invented.
 */
class CustomerAppProviderTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        config(['customer_app.provider_stats_cache_seconds' => 0]);
        $this->a = $this->approvedProvider(['bio' => 'Diving and snorkeling operator.', 'business_type' => 'company', 'city' => 'Jeddah', 'area' => 'Obhur', 'marina_name' => 'Obhur Marina', 'lat' => 21.7, 'lng' => 39.1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function page(?string $token = null, ?int $id = null)
    {
        return $this->ca($token, 'GET', '/providers/'.($id ?? $this->a['provider']->id));
    }

    private function review(int $rating = 5, string $by = 'Sara Al-Qahtani', ?string $comment = 'Lovely', array $attrs = [], $activity = null): Review
    {
        return $this->makeReview($activity ?? $this->a['activity'], $this->customerNamed($by), $rating, $comment, $attrs);
    }

    // --- The page -------------------------------------------------------------------------------------------------------------------

    public function test_anyone_can_read_a_provider_by_id_in_the_pages_shape(): void
    {
        $r = $this->page()->assertOk();

        $this->assertTrue($r->json('status'));
        $this->assertSame('Provider retrieved.', $r->json('message'));
        $this->assertSame(['provider', 'stats', 'rating', 'recent_reviews', 'activities'], array_keys($r->json('data')));
        $this->assertSame(['id', 'uuid', 'business_name', 'slug', 'logo', 'cover', 'bio', 'is_verified', 'business_type', 'city', 'area', 'marina_name', 'lat', 'lng', 'member_since', 'years_hosting', 'languages',
            'categories', 'badges', 'is_favorite'], array_keys($r->json('data.provider')));
        $this->assertSame(['activities_count', 'completed_trips', 'guests_hosted', 'response_rate', 'response_time_minutes'], array_keys($r->json('data.stats')));
        $this->assertSame(['average', 'reviews_count', 'breakdown'], array_keys($r->json('data.rating')));
        $this->assertSame([5, 4, 3, 2, 1], array_keys($r->json('data.rating.breakdown')), 'Every star, five down to one (JSON keys read back as numbers)');
    }

    public function test_the_provider_block_holds_what_the_platform_knows(): void
    {
        $city = $this->makeCity('Jeddah', 'جدة');
        $this->a['provider']->update(['logo' => 'providers/1/logo.jpg']);
        $this->a['provider']->categories()->attach([$this->category('Diving')->id]);
        $p = $this->page()->assertOk()->json('data.provider');

        $this->assertSame([$this->a['provider']->id, $this->a['provider']->uuid, 'Red Sea Divers', $this->a['provider']->slug, true, 'company', 'Obhur', 'Obhur Marina', 21.7, 39.1],
            [$p['id'], $p['uuid'], $p['business_name'], $p['slug'], $p['is_verified'], $p['business_type'], $p['area'], $p['marina_name'], $p['lat'], $p['lng']]);
        $this->assertSame('Diving and snorkeling operator.', $p['bio']);
        $this->assertStringEndsWith('providers/1/logo.jpg', $p['logo']);
        $this->assertSame(['id' => $city->id, 'name_ar' => 'جدة', 'name_en' => 'Jeddah'], $p['city'], 'The platform\'s own city, with its id and both names');
        $this->assertSame('2026-11-15', $p['member_since']);
        $this->assertSame(0, $p['years_hosting']);
        $this->assertSame(['Diving'], array_column($p['categories'], 'name'));
        $this->assertArrayHasKey('icon', $p['categories'][0]);
    }

    public function test_what_the_platform_does_not_hold_is_empty_not_invented(): void
    {
        $p = $this->page()->assertOk()->json('data.provider');

        $this->assertNull($p['cover'], 'A provider has no cover picture');
        $this->assertSame([], $p['languages'], 'Nor a list of languages');
        $this->assertFalse($p['is_favorite'], 'Only activities can be favourites');
        $this->assertSame([], $p['badges']);
    }

    public function test_a_city_the_platform_does_not_know_is_given_as_the_provider_wrote_it_and_no_city_is_null(): void
    {
        $this->assertSame(['id' => null, 'name_ar' => 'Jeddah', 'name_en' => 'Jeddah'], $this->page()->json('data.provider.city'));

        $this->a['provider']->update(['city' => null]);
        $this->assertNull($this->page()->json('data.provider.city'));
    }

    public function test_years_hosting_counts_whole_years_since_the_provider_joined(): void
    {
        $this->a['provider']->forceFill(['created_at' => now()->subYears(3)->subMonths(2)])->save();

        $p = $this->page()->json('data.provider');

        $this->assertSame([3, now()->subYears(3)->subMonths(2)->toDateString()], [$p['years_hosting'], $p['member_since']]);
    }

    // --- Badges ---------------------------------------------------------------------------------------------------------------------

    private function document(string $type, string $status = 'approved', $expires = null): void
    {
        ProviderDocument::create(['provider_id' => $this->a['provider']->id, 'type' => $type, 'title' => $type, 'original_name' => "{$type}.pdf", 'path' => "d/{$type}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10,
            'status' => $status, 'expires_at' => $expires]);
    }

    public function test_badges_come_from_approved_documents_and_a_good_rating(): void
    {
        $this->document('safety_certification');
        $this->document('insurance_certificate');
        config(['customer_app.top_rated_min_reviews' => 2, 'customer_app.top_rated_min_rating' => 4.5]);
        $this->review(5);
        $this->review(5);

        $this->assertSame(['safety_certified', 'insured', 'top_rated'], $this->page()->json('data.provider.badges'));
    }

    public function test_a_document_that_is_not_approved_or_has_expired_earns_no_badge(): void
    {
        $this->document('safety_certification', 'pending');
        $this->document('insurance_certificate', 'approved', now()->subDay());
        $this->document('insurance', 'rejected');

        $this->assertSame([], $this->page()->json('data.provider.badges'));
    }

    public function test_top_rated_needs_enough_reviews_and_a_high_enough_average(): void
    {
        config(['customer_app.top_rated_min_reviews' => 3, 'customer_app.top_rated_min_rating' => 4.5]);
        $this->review(5);
        $this->review(5);
        $this->assertSame([], $this->page()->json('data.provider.badges'), 'Two reviews are not enough');

        $this->review(2);   // three reviews, average 4.0
        $this->assertSame([], $this->page()->json('data.provider.badges'), 'Not high enough');

        $this->review(5);   // four, average 4.25
        $this->review(5);
        $this->review(5);   // seven, average 4.43
        $this->review(5);
        $this->review(5);   // nine, average 4.56
        $this->assertSame(['top_rated'], $this->page()->json('data.provider.badges'));
    }

    // --- Stats ----------------------------------------------------------------------------------------------------------------------

    public function test_activities_count_only_what_customers_can_see(): void
    {
        $this->moreActivity($this->a, 'Snorkel', 200);
        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);
        $suspended = $this->moreActivity($this->a, 'Suspended', 100);
        $suspended->update(['suspended_at' => now()]);

        $this->assertSame(2, $this->page()->json('data.stats.activities_count'));
    }

    public function test_trips_and_guests_count_the_completed_bookings_only(): void
    {
        $c = $this->customerNamed('Guest One');
        $this->makeBooking($this->a, $c, '2026-11-01', 'completed', 1500, ['participants' => json_encode(['adult' => 2, 'child' => 1])]);
        $this->makeBooking($this->a, $c, '2026-11-02', 'completed', 1500, ['participants' => json_encode(['adult' => 4])]);
        $this->makeBooking($this->a, $c, '2026-11-20', 'confirmed');
        $this->makeBooking($this->a, $c, '2026-11-21', 'cancelled');

        $stats = $this->page()->json('data.stats');

        $this->assertSame([2, 7], [$stats['completed_trips'], $stats['guests_hosted']]);
    }

    public function test_trips_of_an_activity_that_is_no_longer_shown_still_count_as_history(): void
    {
        $this->makeBooking($this->a, $this->customerNamed('Guest'), '2026-11-01', 'completed');
        $this->a['activity']->update(['status' => 'draft']);

        $stats = $this->page()->json('data.stats');

        $this->assertSame([0, 1], [$stats['activities_count'], $stats['completed_trips']]);
    }

    private function conversation($customer, array $messages, ?int $providerId = null): void
    {
        $id = DB::table('conversations')->insertGetId(['customer_id' => $customer->id, 'provider_id' => $providerId ?? $this->a['provider']->id, 'activity_id' => $this->a['activity']->id, 'created_at' => $messages[0][1], 'updated_at' => $messages[0][1]]);
        foreach ($messages as [$from, $at]) {
            DB::table('messages')->insert(['conversation_id' => $id, 'sender_type' => $from, 'body' => 'hello', 'created_at' => $at]);
        }
    }

    public function test_the_response_rate_and_time_are_measured_from_real_conversations(): void
    {
        $now = now();
        $this->conversation($this->customerNamed('A'), [['customer', $now->copy()->subDays(2)], ['provider', $now->copy()->subDays(2)->addMinutes(30)]]);
        $this->conversation($this->customerNamed('B'), [['customer', $now->copy()->subDays(3)], ['provider', $now->copy()->subDays(3)->addMinutes(10)]]);
        $this->conversation($this->customerNamed('C'), [['customer', $now->copy()->subDays(1)]]);   // never answered
        $this->conversation($this->customerNamed('D'), [['customer', $now->copy()->subHours(5)]]);   // never answered

        $stats = $this->page()->json('data.stats');

        $this->assertSame([50, 20], [$stats['response_rate'], $stats['response_time_minutes']], 'Two of four answered, after 30 and 10 minutes');
    }

    public function test_with_no_conversations_there_is_nothing_to_measure(): void
    {
        $stats = $this->page()->json('data.stats');

        $this->assertNull($stats['response_rate']);
        $this->assertNull($stats['response_time_minutes']);
    }

    public function test_only_recent_conversations_and_this_providers_count(): void
    {
        $now = now();
        $this->conversation($this->customerNamed('Old'), [['customer', $now->copy()->subDays(200)]]);            // outside the window
        $other = $this->approvedProvider(['business_name' => 'Another']);
        $this->conversation($this->customerNamed('Else'), [['customer', $now->copy()->subDay()]], $other['provider']->id);   // somebody else's
        $this->conversation($this->customerNamed('Mine'), [['customer', $now->copy()->subDay()], ['provider', $now->copy()->subDay()->addMinutes(60)]]);

        $stats = $this->page()->json('data.stats');

        $this->assertSame([100, 60], [$stats['response_rate'], $stats['response_time_minutes']]);
    }

    public function test_only_an_answer_after_the_customer_first_wrote_counts_and_the_wait_runs_from_that_message(): void
    {
        $now = now();
        // The provider had written before the customer did (no answer); the customer then wrote and the provider answered 45 minutes later.
        $this->conversation($this->customerNamed('Late'), [['provider', $now->copy()->subDays(2)], ['customer', $now->copy()->subDay()], ['provider', $now->copy()->subDay()->addMinutes(45)]]);
        // The provider wrote before the customer and never answered after.
        $this->conversation($this->customerNamed('Never'), [['provider', $now->copy()->subDays(2)], ['customer', $now->copy()->subDay()]]);

        $stats = $this->page()->json('data.stats');

        $this->assertSame([50, 45], [$stats['response_rate'], $stats['response_time_minutes']]);
    }

    public function test_the_figures_are_kept_for_a_while_when_that_is_configured(): void
    {
        config(['customer_app.provider_stats_cache_seconds' => 300]);
        $this->makeBooking($this->a, $this->customerNamed('G1'), '2026-11-01', 'completed');
        $this->assertSame(1, $this->page()->json('data.stats.completed_trips'));

        $this->makeBooking($this->a, $this->customerNamed('G2'), '2026-11-02', 'completed');
        $this->assertSame(1, $this->page()->json('data.stats.completed_trips'), 'Kept');

        config(['customer_app.provider_stats_cache_seconds' => 0]);
        $this->assertSame(2, $this->page()->json('data.stats.completed_trips'), 'Not kept: worked out afresh');
    }

    // --- Rating and reviews ---------------------------------------------------------------------------------------------------------

    public function test_the_rating_is_the_average_and_the_breakdown_of_the_visible_reviews(): void
    {
        foreach ([5, 5, 5, 4, 3, 1] as $stars) {
            $this->review($stars);
        }
        $this->review(1)->update(['is_hidden' => true]);   // hidden: not counted

        $rating = $this->page()->json('data.rating');

        $this->assertSame([3.83, 6], [$rating['average'], $rating['reviews_count']]);
        $this->assertSame(['5' => 3, '4' => 1, '3' => 1, '2' => 0, '1' => 1], $rating['breakdown']);
    }

    public function test_with_no_reviews_the_average_is_null_not_zero(): void
    {
        $rating = $this->page()->json('data.rating');

        $this->assertSame([null, 0, ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0]], [$rating['average'], $rating['reviews_count'], $rating['breakdown']]);
    }

    public function test_reviews_of_an_activity_customers_cannot_see_are_not_counted_or_listed(): void
    {
        $hidden = $this->moreActivity($this->a, 'Hidden one', 100);
        $this->review(5);
        $this->review(1, 'Other Person', 'Bad', [], $hidden);
        $hidden->update(['status' => 'draft']);

        $this->assertSame(1, $this->page()->json('data.rating.reviews_count'));
        $this->assertCount(1, $this->ca(null, 'GET', "/providers/{$this->a['provider']->id}/reviews")->json('data'));
    }

    public function test_recent_reviews_are_the_five_newest_with_the_activity_and_the_providers_reply(): void
    {
        foreach (range(1, 7) as $i) {
            $this->review(5, "Customer Number{$i}", "Review {$i}", ['created_at' => now()->subDays(10 - $i)]);
        }
        $newest = Review::where('comment', 'Review 7')->first();
        $newest->reply()->create(['provider_id' => $this->a['provider']->id, 'text' => 'Thank you!', 'is_active' => 1]);

        $recent = $this->page()->json('data.recent_reviews');

        $this->assertCount(5, $recent);
        $this->assertSame(['Review 7', 'Review 6', 'Review 5', 'Review 4', 'Review 3'], array_column($recent, 'text'));
        $this->assertSame(['id', 'name', 'avatar', 'rating', 'text', 'date', 'activity', 'provider_reply'], array_keys($recent[0]));
        $this->assertSame('Customer N.', $recent[0]['name'], 'A customer is never named in full');
        $this->assertSame(['id' => $this->a['activity']->id, 'title' => 'Reef Dive'], $recent[0]['activity']);
        $this->assertSame(['text', 'date'], array_keys($recent[0]['provider_reply']));
        $this->assertSame('Thank you!', $recent[0]['provider_reply']['text']);
        $this->assertNull($recent[1]['provider_reply']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $recent[0]['date']);
    }

    public function test_all_the_reviews_are_listed_a_page_at_a_time_and_agree_with_the_rating(): void
    {
        foreach (range(1, 12) as $i) {
            $this->review($i % 5 + 1, "Customer Number{$i}", "Review {$i}", ['created_at' => now()->subDays(20 - $i)]);
        }
        $count = $this->page()->json('data.rating.reviews_count');

        $first = $this->ca(null, 'GET', "/providers/{$this->a['provider']->id}/reviews?per_page=5")->assertOk();
        $last = $this->ca(null, 'GET', "/providers/{$this->a['provider']->id}/reviews?per_page=5&page=3")->assertOk();

        $this->assertCount(5, $first->json('data'));
        $this->assertCount(2, $last->json('data'));
        $this->assertSame($count, $first->json('meta.total'), 'The list and the rating count the same reviews');
        $this->assertSame([1, 3, 5], [$first->json('meta.current_page'), $first->json('meta.last_page'), $first->json('meta.per_page')]);
        $this->assertSame('Review 12', $first->json('data.0.text'));
        $this->assertSame(['id', 'name', 'avatar', 'rating', 'text', 'date', 'activity', 'provider_reply'], array_keys($first->json('data.0')));
    }

    public function test_the_reviews_can_be_filtered_by_stars(): void
    {
        foreach ([5, 5, 4, 1] as $stars) {
            $this->review($stars);
        }

        $r = $this->ca(null, 'GET', "/providers/{$this->a['provider']->id}/reviews?rating=5")->assertOk();

        $this->assertSame([2, [5, 5]], [$r->json('meta.total'), array_column($r->json('data'), 'rating')]);
    }

    // --- Activities -------------------------------------------------------------------------------------------------------------------

    public function test_the_page_shows_the_providers_activities_and_the_list_pages_through_them(): void
    {
        $this->moreActivity($this->a, 'Snorkel', 200);
        $this->moreActivity($this->a, 'Night Dive', 900);
        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);
        $elsewhere = $this->approvedProvider(['business_name' => 'Another']);
        $this->moreActivity($elsewhere, 'Not mine', 50);

        $onPage = $this->page()->json('data.activities');
        $list = $this->ca(null, 'GET', "/providers/{$this->a['provider']->id}/activities?per_page=2")->assertOk();

        $this->assertCount(3, $onPage);
        $this->assertEqualsCanonicalizing(['Reef Dive', 'Snorkel', 'Night Dive'], array_column($onPage, 'title'));
        $this->assertSame(['id', 'title', 'city', 'image', 'category', 'duration_hours', 'rating', 'reviews_count', 'price', 'price_unit', 'reservation_type', 'is_premium', 'is_favorite'], array_keys($onPage[0]));
        $this->assertSame(['id', 'name'], array_keys($onPage[0]['category']));
        $this->assertCount(2, $list->json('data'));
        $this->assertSame([3, 2], [$list->json('meta.total'), $list->json('meta.last_page')]);
    }

    public function test_the_activities_can_be_sorted_and_narrowed_by_category(): void
    {
        $this->moreActivity($this->a, 'Cheap', 100);
        $this->moreActivity($this->a, 'Dear', 900);
        $url = "/providers/{$this->a['provider']->id}/activities";

        $this->assertSame(['Reef Dive', 'Dear', 'Cheap'], array_column($this->ca(null, 'GET', "{$url}?sort=price_desc")->json('data'), 'title'));   // Reef Dive costs 1,500
        $this->assertSame(['Cheap', 'Dear', 'Reef Dive'], array_column($this->ca(null, 'GET', "{$url}?sort=price_asc")->json('data'), 'title'));
        $this->assertSame(1, $this->ca(null, 'GET', "{$url}?category_id={$this->a['activity']->category_id}")->json('meta.total'), 'Only Reef Dive is in that category');
        $this->assertSame(0, $this->ca(null, 'GET', "{$url}?category_id=999999")->json('meta.total'));
    }

    public function test_a_customers_token_makes_the_activities_favourites_personal(): void
    {
        [, $customer, $token] = $this->signedInCustomer();
        Favorite::create(['customer_id' => $customer->id, 'activity_id' => $this->a['activity']->id]);

        $this->assertTrue($this->page($token)->json('data.activities.0.is_favorite'));
        $this->assertFalse($this->page()->json('data.activities.0.is_favorite'));
        $this->assertTrue($this->ca($token, 'GET', "/providers/{$this->a['provider']->id}/activities")->json('data.0.is_favorite'));
        $this->assertFalse($this->page($token)->json('data.provider.is_favorite'));
    }

    // --- Who is shown -----------------------------------------------------------------------------------------------------------------

    public function test_a_provider_who_is_not_verified_or_whose_account_is_not_active_or_who_is_gone_is_not_found(): void
    {
        $pending = $this->approvedProvider(['verification_status' => 'pending']);
        $suspended = $this->approvedProvider();
        $suspended['user']->update(['status' => 'suspended']);
        $deleted = $this->approvedProvider();
        $deleted['provider']->delete();

        foreach ([$pending['provider']->id, $suspended['provider']->id, $deleted['provider']->id, 999999] as $id) {
            $this->ca(null, 'GET', "/providers/{$id}")->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
            $this->ca(null, 'GET', "/providers/{$id}/reviews")->assertNotFound();
            $this->ca(null, 'GET', "/providers/{$id}/activities")->assertNotFound();
        }
        $this->ca(null, 'GET', '/providers/not-a-number')->assertNotFound();
        $this->ca(null, 'GET', "/providers/{$this->a['provider']->slug}")->assertNotFound();   // the customer app uses ids
    }

    public function test_the_lists_refuse_what_cannot_be_paged_or_sorted(): void
    {
        $base = "/providers/{$this->a['provider']->id}";

        $this->ca(null, 'GET', "{$base}/reviews?per_page=0")->assertStatus(422);
        $this->ca(null, 'GET', "{$base}/reviews?per_page=51")->assertStatus(422);
        $this->ca(null, 'GET', "{$base}/reviews?rating=6")->assertStatus(422);
        $this->ca(null, 'GET', "{$base}/activities?sort=drop_table")->assertStatus(422);
        $this->ca(null, 'GET', "{$base}/activities?page=0")->assertStatus(422);
    }
}
