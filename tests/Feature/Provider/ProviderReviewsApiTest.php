<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Activity;
use App\Models\ReviewReply;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** GET /provider/reviews: the rating summary, how the ratings are spread, and the reviews of the provider's own activities with their replies. */
class ProviderReviewsApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function list(string $query = '', ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/reviews'.$query);
    }

    /** Reviews with these ratings, one customer each, on the provider's activity. */
    private function review(array $ratings, ?Activity $activity = null, ?array $scenario = null): array
    {
        $activity ??= ($scenario ?? $this->a)['activity'];

        return array_map(fn ($rating) => $this->makeReview($activity, $this->customerNamed('Customer '.uniqid()), $rating), $ratings);
    }

    public function test_a_provider_with_no_reviews_sees_zeros(): void
    {
        $data = $this->list()->assertOk()->json('data');

        $this->assertSame(['rating', 'reviews_count', 'rating_distribution', 'reviews'], array_keys($data));
        $this->assertSame([0, 0, []], [$data['rating'], $data['reviews_count'], $data['reviews']]);
        $this->assertSame(['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0], $data['rating_distribution']);
    }

    public function test_the_summary_and_the_distribution_come_from_the_visible_reviews(): void
    {
        $this->review([5, 5, 4, 2]);

        $data = $this->list()->json('data');

        $this->assertSame([4, 4], [$data['rating'], $data['reviews_count']]);
        $this->assertSame(['5' => 2, '4' => 1, '3' => 0, '2' => 1, '1' => 0], $data['rating_distribution'], 'Every star is listed, even one nobody gave');
        $this->assertSame(['5', '4', '3', '2', '1'], array_map('strval', array_keys($data['rating_distribution'])));
        $this->assertStringContainsString('"rating_distribution":{"5":2,"4":1,"3":0,"2":1,"1":0}', $this->list()->getContent(), 'An object keyed by the star, as the spec shows');
    }

    public function test_the_average_keeps_two_decimals(): void
    {
        $this->review([5, 4, 4]);

        $this->assertSame(4.33, $this->list()->json('data.rating'));
    }

    public function test_a_review_an_admin_hid_is_not_counted_or_listed(): void
    {
        [$keep, $hidden] = $this->review([5, 1]);
        $hidden->update(['is_hidden' => true, 'hidden_reason' => 'Abusive', 'hidden_at' => now()]);

        $data = $this->list()->json('data');

        $this->assertSame([5, 1], [$data['rating'], $data['reviews_count']]);
        $this->assertSame([$keep->id], array_column($data['reviews'], 'id'));
        $this->assertSame(0, $data['rating_distribution']['1']);
    }

    public function test_each_review_has_the_specs_fields(): void
    {
        $customer = $this->customerNamed('Sara Al-Qahtani');
        $review = $this->makeReview($this->a['activity'], $customer, 5, 'Amazing experience', ['created_at' => '2026-07-02 10:15:00']);

        $row = $this->list()->json('data.reviews.0');

        $this->assertSame(['id', 'customer_name', 'activity', 'date', 'rating', 'text', 'reply'], array_keys($row));
        $this->assertSame([$review->id, 'Sara Al-Qahtani', $this->a['activity']->title, '2026-07-02T10:15:00Z', 5, 'Amazing experience', null], array_values($row));
    }

    public function test_a_review_without_text_is_listed_with_null(): void
    {
        $this->makeReview($this->a['activity'], $this->customerNamed('Quiet Customer'), 4, null);

        $this->assertNull($this->list()->json('data.reviews.0.text'));
    }

    public function test_the_list_is_newest_first_and_paged_as_the_spec_says(): void
    {
        $dates = ['2026-10-01 09:00:00', '2026-10-03 09:00:00', '2026-10-02 09:00:00'];
        $made = [];
        foreach ($dates as $date) {
            $made[$date] = $this->makeReview($this->a['activity'], $this->customerNamed('C'.uniqid()), 5, 'x', ['created_at' => $date]);
        }

        $first = $this->list('?per_page=2&page=1')->assertOk();
        $this->assertSame([$made['2026-10-03 09:00:00']->id, $made['2026-10-02 09:00:00']->id], array_column($first->json('data.reviews'), 'id'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $first->json('meta'));
        $this->assertSame(3, $first->json('data.reviews_count'), 'The summary covers every review, not just the page');
        $this->assertSame([$made['2026-10-01 09:00:00']->id], array_column($this->list('?per_page=2&page=2')->json('data.reviews'), 'id'));
    }

    public function test_only_the_providers_own_reviews_are_shown(): void
    {
        $other = $this->approvedProvider();
        $this->review([5]);
        $this->review([1, 1], null, $other);

        $mine = $this->list()->json('data');
        $theirs = $this->list('', $other)->json('data');

        $this->assertSame([1, 5], [$mine['reviews_count'], $mine['rating']]);
        $this->assertSame([2, 1], [$theirs['reviews_count'], $theirs['rating']]);
    }

    public function test_the_list_can_be_limited_to_one_activity(): void
    {
        [$second] = $this->makeBookableActivity($this->a['provider'], 'published', ['title' => 'Sunset Cruise']);
        $this->review([5, 5]);
        $this->review([2], $second);

        $data = $this->list('?activity_id='.$second->id)->assertOk()->json('data');

        $this->assertSame([2, 1], [$data['rating'], $data['reviews_count']]);
        $this->assertSame(['Sunset Cruise'], array_unique(array_column($data['reviews'], 'activity')));
        $this->assertSame(['5' => 0, '4' => 0, '3' => 0, '2' => 1, '1' => 0], $data['rating_distribution']);
    }

    public function test_someone_elses_activity_and_a_missing_one_are_answered_alike(): void
    {
        $other = $this->approvedProvider();

        $foreign = $this->list('?activity_id='.$other['activity']->id)->assertNotFound();
        $missing = $this->list('?activity_id=99999999')->assertNotFound();

        $this->assertSame('RESOURCE_NOT_FOUND', $foreign->json('code'));
        $this->assertSame($foreign->json('message'), $missing->json('message'), 'It must not reveal which ids exist');
    }

    public function test_a_bad_filter_or_page_is_a_validation_error(): void
    {
        foreach (['?activity_id=abc', '?activity_id=0', '?per_page=1000', '?page=0'] as $bad) {
            $this->list($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_a_standing_reply_is_shown_and_a_deleted_one_is_not(): void
    {
        [$answered, $deleted] = $this->review([5, 4]);
        ReviewReply::create(['review_id' => $answered->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Thank you Sara', 'is_active' => true]);
        ReviewReply::create(['review_id' => $deleted->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Withdrawn', 'is_active' => null, 'deleted_at' => now()]);

        $rows = collect($this->list()->json('data.reviews'))->keyBy('id');

        $this->assertSame(['id', 'text', 'created_at'], array_keys($rows[$answered->id]['reply']));
        $this->assertSame('Thank you Sara', $rows[$answered->id]['reply']['text']);
        $this->assertNull($rows[$deleted->id]['reply']);
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->list('', $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/reviews')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'GET', '/api/v1/provider/reviews')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
