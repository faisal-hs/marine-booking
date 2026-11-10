<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Conversation;
use App\Models\Favorite;
use App\Models\Message;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** Saving activities, reviewing a trip, and talking to a provider. Everything here is the customer's own; another customer's is "not found". */
class CustomerAppEngagementTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        [, $this->customer, $this->token] = $this->signedInCustomer(['name' => 'Ahmed Al-Harbi']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function id(): int
    {
        return $this->a['activity']->id;
    }

    // --- Favorites --------------------------------------------------------------------------------------------------

    public function test_saving_and_removing_an_activity_has_the_specifications_answers_and_is_safe_to_repeat(): void
    {
        $saved = $this->ca($this->token, 'POST', "/favorites/{$this->id()}")->assertOk();
        $this->assertSame(['Added to saved', ['is_favorite' => true]], [$saved->json('message'), $saved->json('data')]);
        $this->ca($this->token, 'POST', "/favorites/{$this->id()}")->assertOk();
        $this->assertSame(1, Favorite::count(), 'No duplicate row');

        $removed = $this->ca($this->token, 'DELETE', "/favorites/{$this->id()}")->assertOk();
        $this->assertSame(['Removed from saved', ['is_favorite' => false]], [$removed->json('message'), $removed->json('data')]);
        $this->ca($this->token, 'DELETE', "/favorites/{$this->id()}")->assertOk();
        $this->ca($this->token, 'DELETE', '/favorites/99999')->assertOk();
        $this->assertSame(0, Favorite::count());
    }

    public function test_only_an_activity_that_can_be_booked_can_be_saved(): void
    {
        $draft = $this->moreActivity($this->a, 'Draft', 100);
        $draft->update(['status' => 'draft']);

        $this->ca($this->token, 'POST', "/favorites/{$draft->id}")->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->ca($this->token, 'POST', '/favorites/99999')->assertNotFound();
        $this->ca($this->token, 'POST', '/favorites/abc')->assertNotFound();
        $this->assertSame(0, Favorite::count());
    }

    public function test_the_list_is_the_customers_own_newest_saved_first_and_in_card_form(): void
    {
        $second = $this->moreActivity($this->a, 'Second', 300);
        [, $other] = $this->makeCustomer();
        Favorite::create(['customer_id' => $other->id, 'activity_id' => $this->id()]);
        $this->ca($this->token, 'POST', "/favorites/{$this->id()}");
        $this->travel(1)->minutes();
        $this->ca($this->token, 'POST', "/favorites/{$second->id}");

        $r = $this->ca($this->token, 'GET', '/favorites')->assertOk();

        $this->assertSame(['Second', 'Reef Dive'], array_column($r->json('data'), 'title'));
        $this->assertSame([true, true], array_column($r->json('data'), 'is_favorite'));
        $this->assertSame(2, $r->json('meta.total'));
        $this->assertSame(['id', 'title', 'city', 'image', 'duration_hours', 'rating', 'reviews_count', 'price', 'original_price', 'is_premium', 'is_favorite'], array_keys($r->json('data.0')));
    }

    public function test_a_saved_activity_that_is_no_longer_visible_drops_out_and_comes_back_with_it(): void
    {
        $this->ca($this->token, 'POST', "/favorites/{$this->id()}");
        $this->a['activity']->update(['status' => 'inactive']);

        $this->assertSame(0, $this->ca($this->token, 'GET', '/favorites')->json('meta.total'));
        $this->assertSame(1, Favorite::count(), 'It is still saved');

        $this->a['activity']->update(['status' => 'published']);
        $this->assertSame(1, $this->ca($this->token, 'GET', '/favorites')->json('meta.total'));
    }

    // --- Reviews ----------------------------------------------------------------------------------------------------

    private function completed(?array $scenario = null, string $date = '2026-11-01'): int
    {
        return $this->makeBooking($scenario ?? $this->a, $this->customer, $date, 'completed');
    }

    public function test_a_customer_reviews_a_completed_trip_and_gets_the_review_back_in_the_specifications_shape(): void
    {
        $booking = $this->completed();

        $r = $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $booking, 'rating' => 5, 'text' => 'Great trip, will come back again'])->assertCreated();

        $this->assertSame('Review added', $r->json('message'));
        $this->assertSame(['id', 'name', 'avatar', 'date', 'rating', 'text', 'provider_reply'], array_keys($r->json('data')));
        $this->assertSame(['Ahmed A.', 5, 'Great trip, will come back again', '2026-11-15T12:00:00Z', null], [$r->json('data.name'), $r->json('data.rating'), $r->json('data.text'), $r->json('data.date'), $r->json('data.provider_reply')]);
        $review = Review::firstOrFail();
        $this->assertSame([$this->customer->id, $this->id(), $booking], [$review->customer_id, $review->activity_id, $review->reservation_id]);
    }

    public function test_the_activitys_rating_and_count_are_brought_up_to_date(): void
    {
        $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $this->completed(), 'rating' => 4])->assertCreated();

        $this->assertEquals([4, 1], [$this->a['activity']->refresh()->average_rating, $this->a['activity']->reviews_count]);
    }

    public function test_the_text_is_optional_but_when_given_it_is_checked(): void
    {
        $booking = $this->completed();
        $url = "/activities/{$this->id()}/reviews";

        foreach ([['rating' => 0], ['rating' => 6], ['rating' => 'x'], [], ['rating' => 5, 'text' => 'ab'], ['rating' => 5, 'text' => str_repeat('x', 1001)]] as $bad) {
            $this->ca($this->token, 'POST', $url, $bad + ['booking_id' => $booking])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->ca($this->token, 'POST', $url, ['booking_id' => $booking, 'rating' => 3])->assertCreated()->assertJsonPath('data.text', null);
    }

    public function test_a_trip_that_has_not_taken_place_cannot_be_reviewed(): void
    {
        foreach (['confirmed', 'pending', 'cancelled', 'expired'] as $status) {
            $booking = $this->makeBooking($this->a, $this->customer, '2026-12-05', $status);

            $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $booking, 'rating' => 5])->assertStatus(422)->assertJsonPath('code', 'REVIEW_NOT_ALLOWED');
        }
        $this->assertSame(0, Review::count());
    }

    public function test_someone_elses_booking_another_activitys_booking_and_a_made_up_one_are_all_not_found(): void
    {
        [, $other] = $this->makeCustomer();
        $theirs = $this->makeBooking($this->a, $other, '2026-11-01', 'completed');
        $second = $this->moreActivity($this->a, 'Second', 100);
        $mineElsewhere = $this->makeBooking(['activity' => $second, 'option' => $second->bookingOptions()->first(), 'period' => null], $this->customer, '2026-11-01', 'completed');

        foreach ([$theirs, $mineElsewhere, 99999] as $booking) {
            $r = $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $booking, 'rating' => 5])->assertNotFound();
            $this->assertSame('Booking not found.', $r->json('message'));
        }
        $this->ca($this->token, 'POST', '/activities/99999/reviews', ['booking_id' => $theirs, 'rating' => 5])->assertNotFound();
        $this->assertSame(0, Review::count());
    }

    public function test_a_second_review_of_the_same_trip_or_activity_is_refused(): void
    {
        $first = $this->completed(null, '2026-11-01');
        $later = $this->completed(null, '2026-11-08');

        $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $first, 'rating' => 5])->assertCreated();
        $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $first, 'rating' => 1])->assertStatus(409)->assertJsonPath('code', 'REVIEW_NOT_ALLOWED');
        $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $later, 'rating' => 1])->assertStatus(409);
        $this->assertSame(1, Review::count());
    }

    public function test_a_completed_trip_can_be_reviewed_even_if_the_activity_has_since_been_taken_down(): void
    {
        $booking = $this->completed();
        $this->a['activity']->update(['status' => 'inactive']);

        $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $booking, 'rating' => 5])->assertCreated();
    }

    public function test_the_customer_in_the_body_is_never_trusted(): void
    {
        [, $other] = $this->makeCustomer();
        $booking = $this->completed();

        $this->ca($this->token, 'POST', "/activities/{$this->id()}/reviews", ['booking_id' => $booking, 'rating' => 5, 'customer_id' => $other->id])->assertCreated();

        $this->assertSame($this->customer->id, Review::firstOrFail()->customer_id);
    }

    // --- Conversations ----------------------------------------------------------------------------------------------

    private function start(?int $provider = null, ?int $activity = null, ?string $token = null)
    {
        return $this->ca($token ?? $this->token, 'POST', '/conversations', ['provider_id' => $provider ?? $this->a['provider']->id, 'activity_id' => $activity ?? $this->id()]);
    }

    public function test_starting_a_conversation_returns_it_in_the_specifications_shape_and_the_same_one_again(): void
    {
        $this->a['provider']->update(['business_name' => 'Abdullah Marine Services']);

        $first = $this->start()->assertCreated();
        $again = $this->start()->assertOk();

        $this->assertSame(['id', 'user', 'last_message', 'unread_count'], array_keys($first->json('data')));
        $this->assertSame([$this->a['provider']->id, 'Abdullah Marine Services', null], [$first->json('data.user.id'), $first->json('data.user.name'), $first->json('data.user.is_online')]);
        $this->assertSame([null, 0], [$first->json('data.last_message'), $first->json('data.unread_count')]);
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, Conversation::count());
    }

    public function test_a_conversation_is_for_one_customer_provider_and_activity(): void
    {
        $second = $this->moreActivity($this->a, 'Second', 100);
        [, , $otherToken] = $this->signedInCustomer();

        $one = $this->start()->json('data.id');
        $two = $this->start(null, $second->id)->json('data.id');
        $three = $this->start(null, null, $otherToken)->json('data.id');

        $this->assertCount(3, array_unique([$one, $two, $three]));
    }

    public function test_the_provider_and_the_activity_must_belong_together_and_be_real(): void
    {
        $other = $this->approvedProvider();

        foreach ([$this->start($other['provider']->id), $this->start(99999), $this->start(null, 99999), $this->start($this->a['provider']->id, $other['activity']->id)] as $r) {
            $r->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        }
        $this->a['provider']->update(['verification_status' => 'pending']);
        $this->start()->assertNotFound();
        $this->assertSame(0, Conversation::count());
        $this->ca($this->token, 'POST', '/conversations', ['provider_id' => 'x'])->assertStatus(422);
    }

    public function test_the_list_shows_the_last_message_and_the_unread_count_with_the_latest_conversation_first(): void
    {
        $second = $this->moreActivity($this->a, 'Second', 100);
        $idle = $this->start()->json('data.id');
        $busy = $this->start(null, $second->id)->json('data.id');
        $this->ca($this->token, 'POST', "/conversations/{$busy}/messages", ['text' => 'Hi, is the trip still on tomorrow?']);
        Message::create(['conversation_id' => $busy, 'sender_type' => 'provider', 'body' => 'See you tomorrow at 9', 'created_at' => now()->addMinute()]);
        Message::create(['conversation_id' => $busy, 'sender_type' => 'provider', 'body' => 'Bring a towel', 'created_at' => now()->addMinutes(2)]);

        $rows = $this->ca($this->token, 'GET', '/conversations')->assertOk()->json('data');

        $this->assertSame([$busy, $idle], array_column($rows, 'id'), 'Whoever wrote last is first; one nobody wrote in is last');
        $this->assertSame([2, 0], array_column($rows, 'unread_count'));
        $this->assertSame('Bring a towel', $rows[0]['last_message']['text']);
        $this->assertSame(['text', 'sent_at'], array_keys($rows[0]['last_message']));
    }

    public function test_messages_are_newest_first_thirty_a_page_and_marked_mine_or_not(): void
    {
        $c = $this->start()->json('data.id');
        foreach (range(1, 35) as $i) {
            Message::create(['conversation_id' => $c, 'sender_type' => $i % 2 ? 'customer' : 'provider', 'body' => "Message {$i}", 'created_at' => now()->addMinutes($i)]);
        }

        $page1 = $this->ca($this->token, 'GET', "/conversations/{$c}/messages")->assertOk();

        $this->assertCount(30, $page1->json('data'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 30, 'total' => 35], $page1->json('meta'));
        $this->assertSame('Message 35', $page1->json('data.0.text'));
        $this->assertSame(['id', 'text', 'is_mine', 'is_read', 'sent_at'], array_keys($page1->json('data.0')));
        $this->assertTrue($page1->json('data.0.is_mine'));
        $this->assertFalse($page1->json('data.1.is_mine'));
        $this->assertCount(5, $this->ca($this->token, 'GET', "/conversations/{$c}/messages", ['page' => 2])->json('data'));
    }

    public function test_opening_a_page_marks_the_providers_messages_on_it_as_read(): void
    {
        $c = $this->start()->json('data.id');
        Message::create(['conversation_id' => $c, 'sender_type' => 'provider', 'body' => 'Hello', 'created_at' => now()]);
        $this->assertSame(1, $this->ca($this->token, 'GET', '/conversations')->json('data.0.unread_count'));

        $first = $this->ca($this->token, 'GET', "/conversations/{$c}/messages")->json('data.0');
        $this->assertFalse($first['is_read'], 'It was unread when it was handed over');

        $this->assertSame(0, $this->ca($this->token, 'GET', '/conversations')->json('data.0.unread_count'));
        $this->assertTrue($this->ca($this->token, 'GET', "/conversations/{$c}/messages")->json('data.0.is_read'));
    }

    public function test_sending_a_message_has_the_specifications_answer_and_keeps_the_conversation_up_to_date(): void
    {
        $c = $this->start()->json('data.id');

        $r = $this->ca($this->token, 'POST', "/conversations/{$c}/messages", ['text' => '  Hi, is the trip still on tomorrow?  '])->assertCreated();

        $this->assertSame(['id', 'text', 'is_mine', 'is_read', 'sent_at'], array_keys($r->json('data')));
        $this->assertSame(['Hi, is the trip still on tomorrow?', true, false, '2026-11-15T12:00:00Z'], [$r->json('data.text'), $r->json('data.is_mine'), $r->json('data.is_read'), $r->json('data.sent_at')]);
        $this->assertSame('customer', Message::firstOrFail()->sender_type);
        $this->assertNotNull(Conversation::find($c)->last_message_at);
    }

    public function test_a_message_must_have_text_but_not_too_much(): void
    {
        $c = $this->start()->json('data.id');

        foreach ([[], ['text' => ''], ['text' => '   '], ['text' => str_repeat('x', 2001)], ['text' => ['a']]] as $bad) {
            $this->ca($this->token, 'POST', "/conversations/{$c}/messages", $bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertSame(0, Message::count());
    }

    public function test_another_customers_conversation_is_not_found_to_read_or_write(): void
    {
        [, , $otherToken] = $this->signedInCustomer();
        $c = $this->start()->json('data.id');
        $this->ca($this->token, 'POST', "/conversations/{$c}/messages", ['text' => 'Private']);

        $this->ca($otherToken, 'GET', "/conversations/{$c}/messages")->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->ca($otherToken, 'POST', "/conversations/{$c}/messages", ['text' => 'Intruding'])->assertNotFound();
        $this->ca($otherToken, 'GET', '/conversations/99999/messages')->assertNotFound();
        $this->ca($otherToken, 'GET', '/conversations/abc/messages')->assertNotFound();
        $this->assertSame(0, $this->ca($otherToken, 'GET', '/conversations')->json('meta.total'));
        $this->assertSame(1, Message::count());
    }

    public function test_messages_are_limited_to_thirty_a_minute_with_a_budget_of_their_own(): void
    {
        $c = $this->start()->json('data.id');

        // Starting the conversation was the first of the 30 a minute (starting and writing share the budget).
        foreach (range(1, 29) as $i) {
            $this->ca($this->token, 'POST', "/conversations/{$c}/messages", ['text' => "m{$i}"])->assertCreated();
        }

        $this->ca($this->token, 'POST', "/conversations/{$c}/messages", ['text' => 'one too many'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->ca($this->token, 'GET', '/conversations')->assertOk();
    }
}
