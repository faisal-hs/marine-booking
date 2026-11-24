<?php

namespace Tests\Feature\Public;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * A provider with no token, in the older public API's envelope ({success, message, data}, and `pagination` for a list): the page, the reviews and the activities. It is the same data as
 * /customer-app/providers/* (see CustomerAppProviderTest, which tests what the data is); this is that it is served here, by id, uuid or slug, in this envelope.
 */
class PublicProviderTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        config(['customer_app.provider_stats_cache_seconds' => 0]);
        $this->a = $this->approvedProvider(['bio' => 'Diving and snorkeling operator.']);
        $this->makeReview($this->a['activity'], $this->customerNamed('Sara Al-Qahtani'), 5, 'Amazing crew');
        $this->makeReview($this->a['activity'], $this->customerNamed('Omar Khan'), 4, 'Good');
        $this->moreActivity($this->a, 'Snorkel', 200);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_provider_page_is_served_without_a_token_in_the_envelope_of_the_sample(): void
    {
        $r = $this->getJson("/api/v1/public/providers/{$this->a['provider']->id}")->assertOk();

        $this->assertSame(['success', 'message', 'data'], array_keys($r->json()));
        $this->assertTrue($r->json('success'));
        $this->assertSame('Provider retrieved.', $r->json('message'));
        $this->assertSame(['provider', 'stats', 'rating', 'recent_reviews', 'activities'], array_keys($r->json('data')));
        $this->assertSame('Red Sea Divers', $r->json('data.provider.business_name'));
        $this->assertSame([4.5, 2], [$r->json('data.rating.average'), $r->json('data.rating.reviews_count')]);
        $this->assertCount(2, $r->json('data.recent_reviews'));
        $this->assertCount(2, $r->json('data.activities'));
    }

    public function test_the_provider_is_found_by_id_uuid_or_slug(): void
    {
        $p = $this->a['provider'];

        foreach ([$p->id, $p->uuid, $p->slug] as $key) {
            $this->getJson("/api/v1/public/providers/{$key}")->assertOk()->assertJsonPath('data.provider.id', $p->id);
        }
    }

    public function test_it_is_the_same_data_as_the_customer_app_serves(): void
    {
        $public = $this->getJson("/api/v1/public/providers/{$this->a['provider']->id}")->json('data');
        $app = $this->ca(null, 'GET', "/providers/{$this->a['provider']->id}")->json('data');

        $this->assertSame($app, $public);
    }

    public function test_the_reviews_are_listed_in_pages_with_the_pagination_of_this_api(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makeReview($this->a['activity'], $this->customerNamed("Reviewer Number{$i}"), 5, "Review {$i}", ['created_at' => now()->subDays(10 - $i)]);
        }

        $r = $this->getJson("/api/v1/public/providers/{$this->a['provider']->id}/reviews?per_page=3&page=2")->assertOk();

        $this->assertSame(['success', 'message', 'data', 'pagination'], array_keys($r->json()));
        $this->assertSame('Provider reviews retrieved.', $r->json('message'));
        $this->assertSame(['current_page' => 2, 'total_pages' => 3, 'per_page' => 3, 'total_items' => 8], $r->json('pagination'));
        $this->assertCount(3, $r->json('data'));
        $this->assertSame(['id', 'name', 'avatar', 'rating', 'text', 'date', 'activity', 'provider_reply'], array_keys($r->json('data.0')));
    }

    public function test_the_reviews_can_be_filtered_by_stars(): void
    {
        $this->getJson("/api/v1/public/providers/{$this->a['provider']->id}/reviews?rating=4")->assertOk()->assertJsonPath('pagination.total_items', 1);
    }

    public function test_the_activities_are_listed_in_pages(): void
    {
        $this->moreActivity($this->a, 'Night Dive', 900);

        $r = $this->getJson("/api/v1/public/providers/{$this->a['provider']->id}/activities?per_page=2&sort=price_asc")->assertOk();

        $this->assertSame('Provider activities retrieved.', $r->json('message'));
        $this->assertSame(['current_page' => 1, 'total_pages' => 2, 'per_page' => 2, 'total_items' => 3], $r->json('pagination'));
        $this->assertSame(['Snorkel', 'Night Dive'], array_column($r->json('data'), 'title'));
        $this->assertSame(['id', 'title', 'city', 'image', 'category', 'duration_hours', 'rating', 'reviews_count', 'price', 'price_unit', 'reservation_type', 'is_premium', 'is_favorite'], array_keys($r->json('data.0')));
    }

    public function test_a_provider_who_cannot_be_shown_is_not_found_in_this_apis_shape(): void
    {
        $pending = $this->approvedProvider(['verification_status' => 'pending']);

        foreach (["/api/v1/public/providers/{$pending['provider']->id}", '/api/v1/public/providers/999999', '/api/v1/public/providers/no-such-slug',
            "/api/v1/public/providers/{$pending['provider']->id}/reviews", "/api/v1/public/providers/{$pending['provider']->id}/activities"] as $url) {
            $r = $this->getJson($url)->assertNotFound();
            $this->assertSame(['success' => false, 'message' => 'Provider not found.', 'data' => null], $r->json(), $url);
        }
    }

    public function test_the_lists_refuse_what_cannot_be_paged_or_sorted(): void
    {
        $base = "/api/v1/public/providers/{$this->a['provider']->id}";

        $this->getJson("{$base}/reviews?per_page=51")->assertStatus(422);
        $this->getJson("{$base}/activities?sort=nonsense")->assertStatus(422);
    }
}
