<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Review;
use App\Models\ReviewReply;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * POST, PATCH and DELETE /provider/reviews/{id}/reply. One reply stands per review; deleting it keeps a record and frees the review for a new one;
 * a review that is not the provider's (someone else's, hidden, made up) is "not found" whatever is sent.
 */
class ProviderReviewRepliesApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private Review $review;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();
        $this->review = $this->makeReview($this->a['activity'], $this->customerNamed('Sara Al-Qahtani'), 5, 'Amazing experience');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function send(string $method, $id, array $body = [], ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], $method, "/api/v1/provider/reviews/{$id}/reply", $body);
    }

    private function reply(string $text = 'Thank you Sara, happy to see you again'): ReviewReply
    {
        return ReviewReply::create(['review_id' => $this->review->id, 'provider_id' => $this->a['provider']->id, 'text' => $text, 'is_active' => true]);
    }

    // --- Replying ---------------------------------------------------------------------------------------------------

    public function test_a_reply_has_the_specs_shape_and_is_stored_for_this_provider(): void
    {
        $response = $this->send('POST', $this->review->id, ['text' => 'Thank you Sara, happy to see you again'])->assertCreated();

        $this->assertSame(['status' => true, 'message' => 'Reply added'], array_intersect_key($response->json(), ['status' => 1, 'message' => 1]));
        $data = $response->json('data');
        $this->assertSame(['review_id', 'reply'], array_keys($data));
        $this->assertSame(['id', 'text', 'created_at'], array_keys($data['reply']));
        $this->assertSame([$this->review->id, 'Thank you Sara, happy to see you again', '2026-11-15T12:00:00Z'], [$data['review_id'], $data['reply']['text'] ? $data['reply']['text'] : null, $data['reply']['created_at']]);

        $stored = ReviewReply::firstOrFail();
        $this->assertSame([$this->a['provider']->id, true, null], [$stored->provider_id, $stored->is_active, $stored->deleted_at]);
    }

    public function test_the_text_is_trimmed_and_must_be_there_and_not_enormous(): void
    {
        foreach (['', '   ', str_repeat('x', 1001)] as $bad) {
            $this->send('POST', $this->review->id, ['text' => $bad])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->send('POST', $this->review->id, [])->assertStatus(422);
        $this->assertSame(0, ReviewReply::count());

        $this->send('POST', $this->review->id, ['text' => "  Thanks!  \n"])->assertCreated();
        $this->assertSame('Thanks!', ReviewReply::firstOrFail()->text);
    }

    public function test_only_one_reply_stands_per_review(): void
    {
        $this->send('POST', $this->review->id, ['text' => 'First'])->assertCreated();

        $this->send('POST', $this->review->id, ['text' => 'Second'])->assertStatus(409)->assertJsonPath('code', 'REPLY_ALREADY_EXISTS');

        $this->assertSame(['First'], ReviewReply::pluck('text')->all());
    }

    public function test_the_database_itself_allows_only_one_active_reply(): void
    {
        $this->reply('First');

        $this->expectException(QueryException::class);

        $this->reply('Second, at the very same moment');
    }

    // --- Editing ------------------------------------------------------------------------------------------------------

    public function test_a_reply_can_be_edited(): void
    {
        $reply = $this->reply();

        $response = $this->send('PATCH', $this->review->id, ['text' => 'Thank you for your feedback. We hope to see you again.'])->assertOk();

        $this->assertSame('Reply updated', $response->json('message'));
        $this->assertSame(['id' => $reply->id, 'review_id' => $this->review->id, 'text' => 'Thank you for your feedback. We hope to see you again.'], $response->json('data'));
        $this->assertSame(1, ReviewReply::count(), 'Edited in place, not a second reply');
    }

    public function test_a_review_with_no_reply_cannot_have_one_edited_or_deleted(): void
    {
        $this->send('PATCH', $this->review->id, ['text' => 'Hello'])->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->send('DELETE', $this->review->id)->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    // --- Deleting -----------------------------------------------------------------------------------------------------

    public function test_deleting_a_reply_keeps_a_record_of_it_and_says_who_and_when(): void
    {
        $reply = $this->reply();

        $response = $this->send('DELETE', $this->review->id)->assertOk();

        $this->assertSame('Reply deleted', $response->json('message'));
        $this->assertSame(['review_id' => $this->review->id, 'reply' => null], $response->json('data'));
        $kept = $reply->refresh();
        $this->assertSame([null, '2026-11-15 12:00:00', $this->a['user']->id, 'Thank you Sara, happy to see you again'], [$kept->is_active, $kept->deleted_at->toDateTimeString(), $kept->deleted_by_user_id, $kept->text]);
        $this->assertFalse($this->review->refresh()->reply()->exists());
    }

    public function test_after_a_delete_the_review_can_be_answered_again_and_the_old_reply_stays_on_record(): void
    {
        $old = $this->reply('The first answer');
        $this->send('DELETE', $this->review->id)->assertOk();

        $new = $this->send('POST', $this->review->id, ['text' => 'A better answer'])->assertCreated()->json('data.reply.id');

        $this->assertNotSame($old->id, $new);
        $this->assertSame(2, ReviewReply::count());
        $this->assertSame(1, ReviewReply::where('is_active', true)->count());
        $this->assertSame('The first answer', $old->refresh()->text);
    }

    public function test_deleting_twice_is_not_found_the_second_time(): void
    {
        $this->reply();
        $this->send('DELETE', $this->review->id)->assertOk();

        $this->send('DELETE', $this->review->id)->assertNotFound();

        $this->assertSame(1, ReviewReply::count());
    }

    // --- Whose review it is -------------------------------------------------------------------------------------------

    public function test_someone_elses_hidden_and_made_up_reviews_are_all_not_found_and_alike(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->makeReview($other['activity'], $this->customerNamed('Their Customer'), 3);
        $hidden = $this->makeReview($this->a['activity'], $this->customerNamed('Hidden One'), 1, 'Abusive', ['is_hidden' => true, 'hidden_at' => now()]);

        $answers = [
            'foreign' => $this->send('POST', $theirs->id, ['text' => 'Mine now']),
            'hidden' => $this->send('POST', $hidden->id, ['text' => 'Answering a hidden review']),
            'missing' => $this->send('POST', 99999999, ['text' => 'Hello']),
            'text id' => $this->send('POST', 'abc', ['text' => 'Hello']),
        ];

        foreach ($answers as $why => $response) {
            $response->assertNotFound();
            $this->assertSame('RESOURCE_NOT_FOUND', $response->json('code'), $why);
        }
        $this->assertSame(1, count(array_unique(array_map(fn ($r) => $r->json('message'), $answers))), 'It must not reveal which ids exist');
        $this->assertSame(0, ReviewReply::count());
    }

    public function test_a_bad_body_does_not_tell_a_foreign_review_from_a_missing_one(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->makeReview($other['activity'], $this->customerNamed('Their Customer'), 3);

        $foreign = $this->send('POST', $theirs->id, ['text' => '']);
        $missing = $this->send('POST', 99999999, ['text' => '']);

        $this->assertSame([404, 404], [$foreign->getStatusCode(), $missing->getStatusCode()], 'Looked up first, validated after');
        $this->assertSame($foreign->json(), $missing->json());
    }

    public function test_another_providers_reply_cannot_be_edited_or_deleted(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->makeReview($other['activity'], $this->customerNamed('Their Customer'), 3);
        $reply = ReviewReply::create(['review_id' => $theirs->id, 'provider_id' => $other['provider']->id, 'text' => 'Theirs', 'is_active' => true]);

        $this->send('PATCH', $theirs->id, ['text' => 'Hijacked'])->assertNotFound();
        $this->send('DELETE', $theirs->id)->assertNotFound();

        $this->assertSame(['Theirs', true], [$reply->refresh()->text, $reply->is_active]);
    }

    // --- Who sees a reply -------------------------------------------------------------------------------------------------

    public function test_customers_see_the_reply_on_the_activity_but_not_a_deleted_one(): void
    {
        $this->reply('Thank you Sara');
        $second = $this->makeReview($this->a['activity'], $this->customerNamed('Second Customer'), 4, 'Nice');
        ReviewReply::create(['review_id' => $second->id, 'provider_id' => $this->a['provider']->id, 'text' => 'Withdrawn answer', 'is_active' => null, 'deleted_at' => now()]);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $rows = collect($this->getJson("/api/v1/public/activities/{$this->a['activity']->slug}/reviews")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('Thank you Sara', $rows[$this->review->id]['provider_reply']['text']);
        $this->assertArrayHasKey('created_at', $rows[$this->review->id]['provider_reply']);
        $this->assertNull($rows[$second->id]['provider_reply']);
    }

    public function test_an_admin_sees_the_reply_on_the_review_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->reply('Thank you Sara, happy to see you again');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.reviews.show', $this->review))->assertOk()
            ->assertSee(__('admin.reviews.provider_reply'))->assertSee('Thank you Sara, happy to see you again');
    }

    public function test_a_reply_stays_on_record_if_an_admin_later_hides_the_review_but_nobody_sees_it(): void
    {
        $this->reply('Thank you Sara');
        $this->review->update(['is_hidden' => true, 'hidden_at' => now()]);

        $this->assertSame(1, ReviewReply::count());
        $this->assertSame([], $this->hit($this->a['token'], 'GET', '/api/v1/provider/reviews')->json('data.reviews'));
        $this->send('PATCH', $this->review->id, ['text' => 'Edit a hidden one'])->assertNotFound();
    }

    // --- Access and limits ------------------------------------------------------------------------------------------

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->send('POST', $this->review->id, ['text' => 'Hi'], $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'POST', "/api/v1/provider/reviews/{$this->review->id}/reply", ['text' => 'Hi'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'POST', "/api/v1/provider/reviews/{$this->review->id}/reply", ['text' => 'Hi'])->assertUnauthorized();
        $this->assertSame(0, ReviewReply::count());
    }

    public function test_replies_are_limited_to_thirty_a_minute_with_a_budget_of_their_own(): void
    {
        foreach (range(1, 30) as $n) {
            $this->send('POST', 99999999, ['text' => 'x'])->assertNotFound();
        }

        $this->send('POST', 99999999, ['text' => 'x'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->hit($this->a['token'], 'GET', '/api/v1/provider/reviews')->assertOk();
        $this->hit($this->a['token'], 'PUT', '/api/v1/provider/bank-account', ['iban' => 'nope'])->assertStatus(422);
    }
}
