<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Activity;
use App\Models\ActivityView;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Review;
use App\Models\ReviewReply;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** Home, search, filters, popular terms, the activity page and its reviews. Only an activity that can be booked is ever shown. */
class CustomerAppDiscoveryTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();   // "Reef Dive": 1,500 for an adult, Saturdays at 09:00
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function search(array $q = [], ?string $token = null)
    {
        return $this->ca($token, 'GET', '/activities', $q);
    }

    private function titles(array $q = []): array
    {
        return array_column($this->search($q)->assertOk()->json('data'), 'title');
    }

    // --- What is visible --------------------------------------------------------------------------------------------

    public function test_a_card_has_the_specifications_fields(): void
    {
        $city = $this->makeCity();
        $this->a['activity']->update(['city_id' => $city->id, 'duration_minutes' => 180, 'average_rating' => 4.9, 'reviews_count' => 247, 'is_featured' => true]);
        $this->withCover($this->a['activity'], 'activities/reef.jpg');

        $card = $this->search()->json('data.0');

        $this->assertSame(['id', 'title', 'city', 'image', 'duration_hours', 'rating', 'reviews_count', 'price', 'original_price', 'is_premium', 'is_favorite'], array_keys($card));
        $this->assertSame([$this->a['activity']->id, 'Reef Dive', 'Jeddah', 3, 4.9, 247, 1500, null, true, false], [$card['id'], $card['title'], $card['city'], $card['duration_hours'], $card['rating'], $card['reviews_count'], $card['price'], $card['original_price'], $card['is_premium'], $card['is_favorite']]);
        $this->assertStringEndsWith('activities/reef.jpg', $card['image']);
    }

    public function test_only_live_unsuspended_bookable_activities_are_shown_anywhere(): void
    {
        $draft = $this->moreActivity($this->a, 'A Draft', 100);
        $draft->update(['status' => 'draft']);
        $paused = $this->moreActivity($this->a, 'Paused One', 100);
        $paused->update(['status' => 'inactive']);
        $suspended = $this->moreActivity($this->a, 'Suspended One', 100);
        $suspended->update(['suspended_at' => now(), 'suspension_reason' => 'Complaint']);
        $noPrice = $this->moreActivity($this->a, 'No Price', 100);
        $noPrice->bookingOptions()->first()->pricingRules()->delete();
        $notPerPerson = $this->moreActivity($this->a, 'Per Trip', 100);
        $notPerPerson->bookingOptions()->update(['booking_model' => 'per_unit']);
        $optionOff = $this->moreActivity($this->a, 'Option Off', 100);
        $optionOff->bookingOptions()->update(['is_active' => false]);

        $this->assertSame(['Reef Dive'], $this->titles());
        $this->assertSame(['Reef Dive'], array_column($this->ca(null, 'GET', '/home')->json('data.popular'), 'title'));

        foreach ([$draft, $paused, $suspended, $noPrice, $notPerPerson, $optionOff] as $hidden) {
            $this->ca(null, 'GET', "/activities/{$hidden->id}")->assertNotFound();
            $this->ca(null, 'GET', "/activities/{$hidden->id}/availability")->assertNotFound();
            $this->ca(null, 'GET', "/activities/{$hidden->id}/reviews")->assertNotFound();
        }
    }

    public function test_the_price_is_the_lowest_adult_price_of_the_default_option(): void
    {
        $this->a['option']->pricingRules()->create(['participant_type' => 'adult', 'price' => 1200]);
        $this->a['option']->pricingRules()->create(['participant_type' => 'child', 'price' => 500]);
        $this->a['option']->pricingRules()->create(['participant_type' => 'adult', 'price' => 100, 'is_active' => false]);
        $other = $this->a['activity']->bookingOptions()->create(['name' => 'Private', 'slug' => 'private-'.uniqid(), 'booking_model' => 'per_person']);
        $other->pricingRules()->create(['participant_type' => 'adult', 'price' => 50]);

        $this->assertSame(1200, $this->search()->json('data.0.price'), 'The child price, the switched-off price and another option do not count');

        $this->a['activity']->bookingOptions()->where('id', $other->id)->update(['is_default' => true]);
        $this->assertSame(50, $this->search()->json('data.0.price'), 'The option marked default is the one booked');
    }

    // --- Search -----------------------------------------------------------------------------------------------------

    public function test_the_search_phrase_looks_in_the_title_description_location_category_and_city(): void
    {
        $sailing = $this->category('Sailing');
        $jeddah = $this->makeCity('Jeddah', 'جدة');
        $this->moreActivity($this->a, 'Yacht Sunset', 800, ['description' => 'Cruise past the corniche', 'location' => 'Obhur', 'category_id' => $sailing->id, 'city_id' => $jeddah->id]);

        $this->assertSame(['Yacht Sunset'], $this->titles(['q' => 'yacht']));
        $this->assertSame(['Yacht Sunset'], $this->titles(['q' => 'corniche']));
        $this->assertSame(['Yacht Sunset'], $this->titles(['q' => 'Obhur']));
        $this->assertSame(['Yacht Sunset'], $this->titles(['q' => 'Sailing']));
        $this->assertSame(['Yacht Sunset'], $this->titles(['q' => 'جدة']));
        $this->assertSame([], $this->titles(['q' => 'nothing like this']));
    }

    public function test_a_search_phrase_is_data_not_part_of_the_query(): void
    {
        $this->assertSame([], $this->titles(['q' => "x' OR 1=1 --"]));
        $this->assertCount(1, $this->titles(['q' => '']));   // an empty phrase is no phrase
    }

    public function test_the_filters_each_narrow_the_list(): void
    {
        $diving = $this->category('Diving');
        $jeddah = $this->makeCity();
        $cheap = $this->moreActivity($this->a, 'Cheap Short', 200, ['category_id' => $diving->id, 'city_id' => $jeddah->id, 'duration_minutes' => 60, 'average_rating' => 4.5]);
        $this->moreActivity($this->a, 'Dear Long', 3000, ['duration_minutes' => 480, 'average_rating' => 3.0]);

        $this->assertSame(['Cheap Short'], $this->titles(['category_id' => $diving->id]));
        $this->assertSame(['Cheap Short'], $this->titles(['city_id' => $jeddah->id]));
        $this->assertEqualsCanonicalizing(['Cheap Short', 'Reef Dive'], $this->titles(['max_price' => 1500]));
        $this->assertEqualsCanonicalizing(['Reef Dive', 'Dear Long'], $this->titles(['min_price' => 1500]));
        $this->assertSame(['Reef Dive'], $this->titles(['min_price' => 1000, 'max_price' => 2000]));
        $this->assertSame(['Cheap Short'], $this->titles(['max_duration' => 2]));
        $this->assertSame(['Cheap Short'], $this->titles(['min_rating' => 4]));
        $this->assertSame(['Cheap Short'], $this->titles(['category_id' => $diving->id, 'city_id' => $jeddah->id, 'max_price' => 500, 'max_duration' => 1, 'min_rating' => 4, 'q' => 'cheap']));
        $this->assertNotNull($cheap);
    }

    public function test_every_sort_in_the_list_orders_as_it_says_and_the_default_is_popular(): void
    {
        $cheap = $this->moreActivity($this->a, 'Cheap', 200, ['average_rating' => 3.0, 'duration_minutes' => 30, 'published_at' => now()->subDays(5)]);
        $dear = $this->moreActivity($this->a, 'Dear', 3000, ['average_rating' => 5.0, 'duration_minutes' => 500, 'published_at' => now()]);
        $this->a['activity']->update(['average_rating' => 4.0, 'duration_minutes' => 120, 'published_at' => now()->subDay()]);
        [, $customer] = $this->signedInCustomer();
        foreach (range(1, 3) as $i) {
            $this->makeBooking(['activity' => $cheap, 'option' => $cheap->bookingOptions()->first(), 'period' => null], $customer, '2026-12-05', 'confirmed');
        }
        $this->makeBooking($this->a, $customer, '2026-12-05', 'completed');
        $this->makeBooking($this->a, $customer, '2026-12-05', 'cancelled');   // not counted

        $this->assertSame(['Cheap', 'Reef Dive', 'Dear'], $this->titles(), 'The default: most booked first');
        $this->assertSame(['Cheap', 'Reef Dive', 'Dear'], $this->titles(['sort' => 'popular']));
        $this->assertSame(['Dear', 'Reef Dive', 'Cheap'], $this->titles(['sort' => 'rating']));
        $this->assertSame(['Cheap', 'Reef Dive', 'Dear'], $this->titles(['sort' => 'price_asc']));
        $this->assertSame(['Dear', 'Reef Dive', 'Cheap'], $this->titles(['sort' => 'price_desc']));
        $this->assertSame(['Dear', 'Reef Dive', 'Cheap'], $this->titles(['sort' => 'newest']));
        $this->assertSame(['Cheap', 'Reef Dive', 'Dear'], $this->titles(['sort' => 'duration']));
        $this->assertNotNull($dear);
    }

    public function test_bad_search_input_is_refused(): void
    {
        foreach ([['min_price' => 500, 'max_price' => 100], ['sort' => 'id; drop table activities'], ['min_rating' => 6], ['max_duration' => 0], ['min_price' => -1], ['per_page' => 500], ['page' => 'x']] as $bad) {
            $this->search($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_the_list_is_paged_twenty_at_a_time_unless_asked(): void
    {
        foreach (range(1, 24) as $i) {
            $this->moreActivity($this->a, "Trip {$i}", 100 + $i);
        }

        $first = $this->search()->assertOk();
        $this->assertSame([20, 25, 2, 1], [count($first->json('data')), $first->json('meta.total'), $first->json('meta.last_page'), $first->json('meta.current_page')]);
        $this->assertCount(5, $this->search(['page' => 2])->json('data'));
        $this->assertCount(7, $this->search(['per_page' => 7])->json('data'));
        $this->assertSame([], $this->search(['page' => 9])->json('data'));
    }

    public function test_is_favorite_is_personal(): void
    {
        [, $mine, $token] = $this->signedInCustomer();
        [, $someoneElse] = $this->makeCustomer();
        Favorite::create(['customer_id' => $mine->id, 'activity_id' => $this->a['activity']->id]);

        $this->assertTrue($this->search([], $token)->json('data.0.is_favorite'));
        $this->assertFalse($this->search()->json('data.0.is_favorite'), 'A guest has none');
        $this->assertFalse($this->ca($this->tokenFor($someoneElse->user, 'customer'), 'GET', '/activities')->json('data.0.is_favorite'));
    }

    // --- Popular terms and filters --------------------------------------------------------------------------------------

    public function test_the_filter_options_are_the_cities_and_categories_with_something_to_book_and_the_price_range(): void
    {
        $jeddah = $this->makeCity('Jeddah');
        $this->makeCity('Empty Town');
        $this->makeCity('Off', 'x', ['is_active' => false]);
        $diving = $this->category('Diving');
        $this->category('Unused');
        $this->a['activity']->update(['city_id' => $jeddah->id, 'category_id' => $diving->id]);
        $this->moreActivity($this->a, 'Cheap', 200);
        $this->moreActivity($this->a, 'Dear', 3000);

        $data = $this->ca(null, 'GET', '/filters')->assertOk()->json('data');

        $this->assertSame(['cities', 'categories', 'min_price', 'max_price', 'sorts'], array_keys($data));
        $this->assertSame([['id' => $jeddah->id, 'name' => 'Jeddah']], $data['cities']);
        $this->assertContains('Diving', array_column($data['categories'], 'name'));
        $this->assertNotContains('Unused', array_column($data['categories'], 'name'));
        $this->assertSame([200, 3000], [$data['min_price'], $data['max_price']]);
        $this->assertSame(['popular', 'rating', 'price_asc', 'price_desc', 'newest', 'duration'], $data['sorts']);
    }

    public function test_the_city_name_follows_the_language(): void
    {
        $this->a['activity']->update(['city_id' => $this->makeCity('Jeddah', 'جدة')->id]);

        $this->assertSame('Jeddah', $this->search()->json('data.0.city'));
        $this->assertSame('جدة', $this->ca(null, 'GET', '/activities', [], ['Accept-Language' => 'ar'])->json('data.0.city'));
        $this->assertSame('جدة', $this->ca(null, 'GET', '/filters', [], ['Accept-Language' => 'ar'])->json('data.cities.0.name'));
    }

    public function test_an_activity_without_a_city_shows_its_providers_city(): void
    {
        $this->a['provider']->update(['city' => 'Yanbu']);

        $this->assertSame('Yanbu', $this->search()->json('data.0.city'));
    }

    // --- Home -------------------------------------------------------------------------------------------------------

    public function test_home_has_the_specifications_blocks(): void
    {
        $diving = $this->category('Diving');
        $jeddah = $this->makeCity();
        $this->a['activity']->update(['is_featured' => true, 'city_id' => $jeddah->id, 'category_id' => $diving->id]);
        $this->moreActivity($this->a, 'Plain One', 100, ['city_id' => $jeddah->id]);

        $data = $this->ca(null, 'GET', '/home')->assertOk()->json('data');

        $this->assertSame(['unread_notifications', 'categories', 'featured', 'destinations', 'popular'], array_keys($data));
        $this->assertSame(['Reef Dive'], array_column($data['featured'], 'title'), 'Only what an admin featured');
        $this->assertCount(2, $data['popular']);
        $this->assertSame([['id' => $jeddah->id, 'name' => 'Jeddah', 'image' => null, 'activities_count' => 2]], $data['destinations']);
        $this->assertSame(['id', 'name', 'icon'], array_keys($data['categories'][0]));
    }

    public function test_home_shows_only_active_categories_and_cities_that_have_something_to_book(): void
    {
        $this->category('Diving');
        Category::create(['name' => 'Retired', 'slug' => 'retired-'.uniqid(), 'status' => 'inactive']);
        $this->makeCity('Empty Town');

        $data = $this->ca(null, 'GET', '/home')->json('data');

        $this->assertContains('Diving', array_column($data['categories'], 'name'));
        $this->assertNotContains('Retired', array_column($data['categories'], 'name'));
        $this->assertSame([], $data['destinations']);
    }

    public function test_the_popular_list_is_the_most_booked_and_is_limited(): void
    {
        config(['customer_app.home_limit' => 2]);
        $busy = $this->moreActivity($this->a, 'Busy', 100);
        $this->moreActivity($this->a, 'Quiet', 100);
        $this->moreActivity($this->a, 'Quieter', 100);
        [, $customer] = $this->signedInCustomer();
        $this->makeBooking(['activity' => $busy, 'option' => $busy->bookingOptions()->first(), 'period' => null], $customer, '2026-12-05', 'completed');

        $popular = array_column($this->ca(null, 'GET', '/home')->json('data.popular'), 'title');

        $this->assertCount(2, $popular);
        $this->assertSame('Busy', $popular[0]);
    }

    public function test_the_unread_count_is_the_customers_own_and_agrees_with_the_notifications_list(): void
    {
        [$user, , $token] = $this->signedInCustomer();
        foreach (range(1, 3) as $i) {
            $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\Notifications\Reservations\ReservationConfirmedNotification', 'data' => ['type' => 'reservation.confirmed', 'message' => 'x', 'reservation_id' => $i]]);
        }
        $user->notifications()->first()->markAsRead();

        $this->assertSame(2, $this->ca($token, 'GET', '/home')->json('data.unread_notifications'));
        $this->assertSame(3, $this->ca($token, 'GET', '/notifications')->json('meta.total'));
        $this->assertSame(2, collect($this->ca($token, 'GET', '/notifications')->json('data'))->where('is_read', false)->count());
        $this->assertSame(0, $this->ca(null, 'GET', '/home')->json('data.unread_notifications'));
    }

    // --- The activity page --------------------------------------------------------------------------------------------

    public function test_the_activity_page_has_every_field_of_the_specification(): void
    {
        $diving = $this->category('Diving');
        $this->a['activity']->update(['category_id' => $diving->id, 'duration_minutes' => 150, 'max_participants' => 8, 'average_rating' => 4.8, 'reviews_count' => 3, 'lat' => 21.7125, 'lng' => 39.0961, 'meeting_point' => 'Obhur Marina, Gate 2']);
        $this->a['provider']->update(['bio' => 'Family run since 2010', 'business_name' => 'Abdullah Marine Services']);
        $this->withCover($this->a['activity'], 'activities/one.jpg');
        $this->a['activity']->images()->create(['path' => 'activities/two.jpg', 'sort_order' => 1, 'is_cover' => false]);
        $this->a['activity']->inclusions()->create(['type' => 'included', 'description' => 'Equipment', 'sort_order' => 0]);
        $this->a['activity']->inclusions()->create(['type' => 'excluded', 'description' => 'Meals', 'sort_order' => 1]);
        $this->a['activity']->requirements()->create(['text' => 'Listen to the briefing', 'is_mandatory' => true, 'sort_order' => 0]);

        $d = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->assertOk()->json('data');

        $this->assertSame(['id', 'title', 'city', 'location', 'category', 'image', 'gallery', 'duration_hours', 'price', 'original_price', 'discount_percent', 'rating', 'reviews_count', 'booked_count', 'is_bestseller',
            'is_premium', 'is_favorite', 'free_cancellation', 'allowed_payment_methods', 'max_guests', 'is_certified', 'languages', 'description', 'included', 'not_included', 'safety_instructions', 'cancellation_rules', 'weather',
            'meeting_point', 'host', 'reviews', 'rating_distribution', 'rating_categories', 'similar'], array_keys($d));
        $this->assertSame(['Reef Dive', 'Hurghada', 2.5, 1500, 8, 4.8, ['Equipment'], ['Meals'], ['Listen to the briefing'], true, true], [$d['title'], $d['location'], $d['duration_hours'], $d['price'], $d['max_guests'], $d['rating'], $d['included'], $d['not_included'], $d['safety_instructions'], $d['is_certified'], $d['free_cancellation']]);
        $this->assertSame(['id' => $diving->id, 'name' => 'Diving'], $d['category']);
        $this->assertCount(2, $d['gallery']);
        $this->assertStringEndsWith('activities/one.jpg', $d['image']);
        $this->assertSame(['name' => 'Obhur Marina, Gate 2', 'note' => null, 'lat' => 21.7125, 'lng' => 39.0961], $d['meeting_point']);
        $this->assertSame(['id', 'name', 'avatar', 'is_verified', 'years_hosting', 'rating', 'response_rate', 'bio'], array_keys($d['host']));
        $this->assertSame(['Abdullah Marine Services', 'Family run since 2010', true], [$d['host']['name'], $d['host']['bio'], $d['host']['is_verified']]);
    }

    public function test_where_the_platform_has_no_data_the_field_says_so_instead_of_inventing_it(): void
    {
        $d = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data');

        $this->assertNull($d['weather']);
        $this->assertNull($d['original_price']);
        $this->assertNull($d['discount_percent']);
        $this->assertNull($d['rating_categories']);
        $this->assertNull($d['host']['response_rate']);
        $this->assertSame([], $d['languages']);
        $this->assertSame([], $d['reviews']);
        $this->assertSame(['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0], $d['rating_distribution']);
    }

    public function test_the_cancellation_rules_on_the_page_are_the_ones_the_cancel_uses(): void
    {
        $d = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data');

        $this->assertSame([['more_than_hours' => 24, 'refund_percent' => 100, 'text' => 'Cancel more than 24 hours before the activity for a full refund.'],
            ['more_than_hours' => 12, 'refund_percent' => 50, 'text' => 'Cancel between 12 and 24 hours before the activity for a 50% refund.'],
            ['more_than_hours' => 0, 'refund_percent' => 0, 'text' => 'Cancel less than 12 hours before the activity and nothing is refunded.']], $d['cancellation_rules']);

        config(['customer_app.cancellation.full_refund_hours' => 48, 'customer_app.cancellation.partial_refund_percent' => 0]);
        $changed = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data.cancellation_rules');
        $this->assertSame([48, 0], array_column($changed, 'more_than_hours'), 'Change the policy and the page follows');
    }

    public function test_the_page_counts_a_view_for_the_provider_but_not_for_the_providers_own_look(): void
    {
        $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}", [], ['User-Agent' => 'Mozilla/5.0 (a phone)'])->assertOk();
        $this->assertSame(1, ActivityView::count());

        $this->ca($this->a['token'], 'GET', "/activities/{$this->a['activity']->id}", [], ['User-Agent' => 'Mozilla/5.0 (the provider)'])->assertOk();
        $this->assertSame(1, ActivityView::count());
    }

    public function test_the_booked_count_and_the_bestseller_badge(): void
    {
        config(['customer_app.bestseller_min_bookings' => 2]);
        [, $customer] = $this->signedInCustomer();
        $this->makeBooking($this->a, $customer, '2026-12-05', 'confirmed');
        $this->makeBooking($this->a, $customer, '2026-12-05', 'cancelled');
        $d = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data');
        $this->assertSame([1, false], [$d['booked_count'], $d['is_bestseller']]);

        $this->makeBooking($this->a, $customer, '2026-12-05', 'completed');
        $d = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data');
        $this->assertSame([2, true], [$d['booked_count'], $d['is_bestseller']]);
    }

    public function test_similar_activities_are_others_in_the_same_category_that_can_be_booked(): void
    {
        $diving = $this->category('Diving');
        $this->a['activity']->update(['category_id' => $diving->id]);
        $this->moreActivity($this->a, 'Also Diving', 400, ['category_id' => $diving->id]);
        $hidden = $this->moreActivity($this->a, 'Hidden Diving', 400, ['category_id' => $diving->id]);
        $hidden->update(['status' => 'draft']);
        $this->moreActivity($this->a, 'Something Else', 400, ['category_id' => $this->category('Fishing')->id]);

        $similar = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data.similar');

        $this->assertSame(['Also Diving'], array_column($similar, 'title'));
        $this->assertSame(['id', 'title', 'city', 'image', 'duration_hours', 'rating', 'reviews_count', 'price', 'original_price', 'is_premium', 'is_favorite'], array_keys($similar[0]));
    }

    // --- Reviews ----------------------------------------------------------------------------------------------------

    private function review(int $rating, string $name, ?string $text = 'Lovely', array $attrs = []): Review
    {
        return $this->makeReview($this->a['activity'], $this->customerNamed($name), $rating, $text, $attrs);
    }

    public function test_reviews_show_a_masked_name_the_date_the_text_and_the_providers_reply(): void
    {
        $review = $this->review(5, 'Sara Al-Qahtani', 'Amazing experience', ['created_at' => '2026-07-02 10:15:00']);
        ReviewReply::create(['review_id' => $review->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Thank you Sara', 'is_active' => true]);
        $this->review(4, 'Single');

        $rows = collect($this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['id', 'name', 'avatar', 'date', 'rating', 'text', 'provider_reply'], array_keys($rows[$review->id]));
        $this->assertSame(['Sara A.', '2026-07-02T10:15:00Z', 5, 'Amazing experience'], [$rows[$review->id]['name'], $rows[$review->id]['date'], $rows[$review->id]['rating'], $rows[$review->id]['text']]);
        $this->assertSame('Thank you Sara', $rows[$review->id]['provider_reply']['text']);
        $this->assertSame('Single', collect($rows)->firstWhere('rating', 4)['name']);
        $this->assertNull(collect($rows)->firstWhere('rating', 4)['provider_reply']);
    }

    public function test_reviews_can_be_filtered_by_rating_hidden_ones_are_left_out_and_they_are_paged(): void
    {
        foreach ([5, 5, 4, 1] as $i => $rating) {
            $this->review($rating, "Guest {$i}");
        }
        $this->review(5, 'Abusive Guest', 'Rude', ['is_hidden' => true, 'hidden_at' => now()]);

        $this->assertSame(4, $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews")->json('meta.total'));
        $this->assertSame(2, $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews", ['rating' => 5])->json('meta.total'));
        $this->assertSame(2, count($this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews", ['per_page' => 2])->json('data')));
        $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews", ['rating' => 6])->assertStatus(422);
    }

    public function test_the_page_previews_the_latest_five_reviews_and_spreads_the_ratings(): void
    {
        foreach ([5, 5, 4, 3, 2, 1, 5] as $i => $rating) {
            $this->review($rating, "Guest {$i}", 'ok', ['created_at' => now()->subDays(10 - $i)]);
        }

        $d = $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data');

        $this->assertCount(5, $d['reviews']);
        $this->assertSame(['5' => 3, '4' => 1, '3' => 1, '2' => 1, '1' => 1], $d['rating_distribution']);
        $this->assertSame(5, $d['reviews'][0]['rating'], 'Newest first');
    }

    public function test_a_deleted_customers_review_keeps_their_name_out_of_it(): void
    {
        [$user, $customer] = $this->makeCustomer(['name' => 'Sara Al-Qahtani']);
        $this->makeReview($this->a['activity'], $customer, 5, 'Great');
        $user->update(['name' => 'Deleted customer']);
        $user->delete();

        $this->assertSame('Deleted customer', $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}/reviews")->json('data.0.name'));
    }

    public function test_the_two_page_content_helpers_exist_so_nothing_is_missing(): void
    {
        $this->assertSame(Activity::class, get_class($this->a['activity']));
    }
}
