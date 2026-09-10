<?php

namespace Tests\Feature\Public;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCategory(): Category
    {
        return Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
    }

    protected function makeActivity(Category $category, string $status = 'published'): Activity
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Red Sea Diving Trip',
            'slug' => 'red-sea-diving-' . uniqid(),
            'status' => $status,
        ]);
    }

    // --- Homepage ---

    public function test_homepage_loads_successfully(): void
    {
        $response = $this->get(route('public.home'));

        $response->assertOk();
    }

    public function test_homepage_shows_active_categories(): void
    {
        $category = $this->makeCategory();

        $response = $this->get(route('public.home'));

        $response->assertOk()->assertSee('Diving');
    }

    public function test_homepage_shows_published_activities_only(): void
    {
        $category = $this->makeCategory();
        $this->makeActivity($category, 'published');
        $draft = $this->makeActivity($category, 'draft');
        $draft->update(['title' => 'Hidden Draft Activity']);

        $response = $this->get(route('public.home'));

        $response->assertOk()
            ->assertSee('Red Sea Diving Trip')
            ->assertDontSee('Hidden Draft Activity');
    }

    // --- Category page ---

    public function test_category_page_shows_only_that_categorys_activities(): void
    {
        $categoryA = $this->makeCategory();
        $categoryB = Category::create(['name' => 'Boat Trips', 'slug' => 'boats-' . uniqid(), 'status' => 'active']);
        $this->makeActivity($categoryA);
        $activityB = $this->makeActivity($categoryB);
        $activityB->update(['title' => 'Sunset Boat Cruise']);

        $response = $this->get(route('public.categories.show', $categoryA));

        $response->assertOk()
            ->assertSee('Red Sea Diving Trip')
            ->assertDontSee('Sunset Boat Cruise');
    }

    public function test_inactive_category_returns_404(): void
    {
        $category = Category::create(['name' => 'Old Category', 'slug' => 'old-' . uniqid(), 'status' => 'inactive']);

        $response = $this->get(route('public.categories.show', $category));

        $response->assertStatus(404);
    }

    // --- Activities search/listing ---

    public function test_activities_index_lists_published_activities(): void
    {
        $category = $this->makeCategory();
        $this->makeActivity($category);

        $response = $this->get(route('public.activities.index'));

        $response->assertOk()->assertSee('Red Sea Diving Trip');
    }

    public function test_activities_can_be_filtered_by_search_term(): void
    {
        $category = $this->makeCategory();
        $this->makeActivity($category);
        $other = $this->makeActivity($category);
        $other->update(['title' => 'Kayaking Adventure']);

        $response = $this->get(route('public.activities.index', ['search' => 'Diving']));

        $response->assertOk()
            ->assertSee('Red Sea Diving Trip')
            ->assertDontSee('Kayaking Adventure');
    }

    public function test_activities_can_be_filtered_by_category(): void
    {
        $categoryA = $this->makeCategory();
        $categoryB = Category::create(['name' => 'Boat Trips', 'slug' => 'boats-' . uniqid(), 'status' => 'active']);
        $this->makeActivity($categoryA);
        $activityB = $this->makeActivity($categoryB);
        $activityB->update(['title' => 'Sunset Boat Cruise']);

        $response = $this->get(route('public.activities.index', ['category_id' => $categoryA->id]));

        $response->assertOk()
            ->assertSee('Red Sea Diving Trip')
            ->assertDontSee('Sunset Boat Cruise');
    }

    public function test_draft_activities_never_appear_in_public_listing(): void
    {
        $category = $this->makeCategory();
        $draft = $this->makeActivity($category, 'draft');
        $draft->update(['title' => 'Secret Draft Activity']);

        $response = $this->get(route('public.activities.index'));

        $response->assertOk()->assertDontSee('Secret Draft Activity');
    }

    // --- Activity detail ---

    public function test_activity_detail_page_loads_for_a_published_activity(): void
    {
        $category = $this->makeCategory();
        $activity = $this->makeActivity($category);

        $response = $this->get(route('public.activities.show', $activity->slug));

        $response->assertOk()->assertSee('Red Sea Diving Trip');
    }

    public function test_activity_detail_shows_requirements_and_inclusions(): void
    {
        $category = $this->makeCategory();
        $activity = $this->makeActivity($category);
        $activity->requirements()->create(['text' => 'Must know how to swim', 'is_mandatory' => true, 'sort_order' => 0]);
        $activity->inclusions()->create(['type' => 'included', 'description' => 'Diving equipment', 'sort_order' => 0]);

        $response = $this->get(route('public.activities.show', $activity->slug));

        $response->assertOk()
            ->assertSee('Must know how to swim')
            ->assertSee('Diving equipment');
    }

    public function test_activity_detail_shows_booking_options_and_pricing(): void
    {
        $category = $this->makeCategory();
        $activity = $this->makeActivity($category);
        $option = $activity->bookingOptions()->create(['name' => 'Standard Package', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person', 'is_active' => true]);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500, 'currency' => 'EGP', 'is_active' => true]);

        $response = $this->get(route('public.activities.show', $activity->slug));

        $response->assertOk()
            ->assertSee('Standard Package')
            ->assertSee('1,500.00');
    }

    public function test_activity_detail_shows_visible_reviews(): void
    {
        $category = $this->makeCategory();
        $activity = $this->makeActivity($category);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        $customerUser = User::factory()->customer()->create(['name' => 'Jane Diver']);
        $customer = Customer::create(['user_id' => $customerUser->id]);
        Reservation::create([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->subDay()->toDateString(),
            'status' => 'completed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 1000,
            'total' => 1000,
            'pricing_breakdown' => [],
        ]);
        app(ReviewService::class)->create($customer, $activity, 5, 'Amazing trip!');

        $response = $this->get(route('public.activities.show', $activity->slug));

        $response->assertOk()
            ->assertSee('Jane Diver')
            ->assertSee('Amazing trip!');
    }

    public function test_draft_activity_detail_page_returns_404(): void
    {
        $category = $this->makeCategory();
        $draft = $this->makeActivity($category, 'draft');

        $response = $this->get(route('public.activities.show', $draft->slug));

        $response->assertStatus(404);
    }

    public function test_nonexistent_activity_slug_returns_404(): void
    {
        $response = $this->get(route('public.activities.show', 'does-not-exist'));

        $response->assertStatus(404);
    }

    // --- Locale switching ---

    public function test_locale_defaults_to_english(): void
    {
        $response = $this->get(route('public.home'));

        $response->assertOk()->assertSee('lang="en"', false);
    }

    public function test_switching_locale_to_arabic_persists_in_session(): void
    {
        $this->get(route('public.locale.switch', 'ar'));

        $response = $this->get(route('public.home'));

        $response->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_admin_and_public_locale_preferences_are_independent(): void
    {
        // Switching the PUBLIC locale must never affect a separately
        // established admin_locale session value, and vice versa —
        // they are deliberately different session keys.
        session(['admin_locale' => 'ar']);

        $this->get(route('public.locale.switch', 'en'));
        $response = $this->get(route('public.home'));

        $response->assertOk()->assertSee('lang="en"', false);
        $this->assertEquals('ar', session('admin_locale'));
    }

    public function test_invalid_locale_is_ignored(): void
    {
        $this->get(route('public.locale.switch', 'fr'));

        $response = $this->get(route('public.home'));

        $response->assertOk()->assertSee('lang="en"', false);
    }
}
