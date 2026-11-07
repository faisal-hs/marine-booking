<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Review;
use App\Services\NotificationFeed;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/dashboard. Today is Sunday 15 November 2026 and the provider's commission is 10%.
 *
 *   completed in November   1000 (100 refunded) + 2000                  gross 3000, net (3000 - 100) x 0.9 = 2610
 *   completed in October    5000                                         counts towards the available balance only
 *   confirmed               3000 on the 20th + 1800 today                pending balance (3000 + 1800) x 0.9 = 4320
 *   available balance       (1000 + 2000 + 5000 - 100) x 0.9 = 7110
 */
class ProviderDashboardApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function dashboard(?array $as = null): array
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/dashboard')->assertOk()->json('data');
    }

    private function bookEverything(): void
    {
        $fatima = $this->customerNamed('Fatima Al-Zahrani');
        $first = $this->makeBooking($this->a, $fatima, '2026-11-05', 'completed', 1000);
        $this->makePayment($first, 1000, 100);
        $this->makePayment($this->makeBooking($this->a, $fatima, '2026-11-10', 'completed', 2000), 2000);
        $this->makePayment($this->makeBooking($this->a, $fatima, '2026-10-20', 'completed', 5000), 5000);
        $this->makePayment($this->makeBooking($this->a, $fatima, '2026-11-20', 'confirmed', 3000), 3000);
        $this->makePayment($this->makeBooking($this->a, $fatima, '2026-11-15', 'confirmed', 1800, ['participants' => json_encode(['adult' => 6])]), 1800);
        $this->makeBooking($this->a, $fatima, '2026-11-16', 'pending', 700);
        $this->makeBooking($this->a, $fatima, '2026-11-15', 'pending', 900);
        $this->makeBooking($this->a, $fatima, '2026-11-14', 'cancelled', 900);
    }

    public function test_it_has_exactly_the_specs_fields(): void
    {
        $this->assertSame(['business_name', 'rating', 'reviews_count', 'is_verified', 'unread_notifications', 'month_revenue', 'available_balance', 'pending_balance',
            'month_bookings', 'needs_action', 'todays_bookings'], array_keys($this->dashboard()));
    }

    public function test_gross_is_completed_bookings_this_month_and_net_is_after_refunds_and_commission(): void
    {
        $this->bookEverything();

        $this->assertEquals(['gross' => 3000, 'net' => 2610], $this->dashboard()['month_revenue'], 'October is not this month; the refund and the 10% commission come off');
    }

    public function test_with_no_commission_net_is_gross_less_refunds(): void
    {
        $this->bookEverything();
        $this->a['provider']->update(['commission_rate' => 0]);

        $this->assertEquals(['gross' => 3000, 'net' => 2900], $this->dashboard()['month_revenue']);
    }

    public function test_the_balances_are_provisional_net_of_completed_and_net_of_confirmed(): void
    {
        $this->bookEverything();

        $dashboard = $this->dashboard();

        $this->assertEquals(7110, $dashboard['available_balance'], 'Every completed booking, net of refunds and commission');
        $this->assertEquals(4320, $dashboard['pending_balance'], 'Confirmed, not yet completed');
    }

    public function test_it_counts_the_months_bookings_and_those_waiting_for_the_provider(): void
    {
        $this->bookEverything();

        $dashboard = $this->dashboard();

        // November, not cancelled: 2 completed + 2 confirmed (20th, today) + 2 pending (16th, today) = 6
        $this->assertSame(6, $dashboard['month_bookings']);
        $this->assertSame(2, $dashboard['needs_action'], 'Pending, any day');
    }

    public function test_todays_bookings_are_todays_in_the_lists_format(): void
    {
        $this->bookEverything();

        $today = $this->dashboard()['todays_bookings'];

        $this->assertCount(2, $today, 'Confirmed and pending, not the cancelled one of yesterday');
        $this->assertSame(['id', 'customer_name', 'activity', 'date_time', 'guests', 'amount', 'status'], array_keys($today[0]));
        $this->assertSame(['confirmed', 'pending'], array_column($today, 'status'));
        $this->assertSame(6, $today[0]['guests']);
        $this->assertSame('2026-11-15T09:00:00Z', $today[0]['date_time']);
    }

    public function test_rating_and_review_count_ignore_hidden_reviews(): void
    {
        // One review per customer per activity, so each review has its own customer.
        foreach ([[5, false], [4, false], [1, true]] as [$rating, $hidden]) {
            $customer = $this->customerNamed("Reviewer {$rating}");
            $id = $this->makeBooking($this->a, $customer, '2026-10-0'.($rating + 1), 'completed');
            Review::create(['customer_id' => $customer->id, 'activity_id' => $this->a['activity']->id, 'reservation_id' => $id, 'rating' => $rating, 'comment' => 'ok',
                'is_hidden' => $hidden, 'hidden_reason' => $hidden ? 'spam' : null, 'hidden_at' => $hidden ? now() : null]);
        }

        $dashboard = $this->dashboard();

        $this->assertEquals(4.5, $dashboard['rating']);
        $this->assertSame(2, $dashboard['reviews_count']);
    }

    public function test_unread_notifications_are_the_providers_own_feed_only(): void
    {
        $this->giveNotification($this->a['user'], ['message' => 'New booking'], NotificationFeed::PROVIDER_ONLY[0]);
        $this->giveNotification($this->a['user'], ['message' => 'Another'], NotificationFeed::PROVIDER_ONLY[0]);
        $this->giveNotification($this->a['user'], ['message' => 'Already read'], NotificationFeed::PROVIDER_ONLY[0], true);
        $this->giveNotification($this->a['user'], ['message' => 'For the customer account'], NotificationFeed::CUSTOMER_ONLY[0]);

        $this->assertSame(2, $this->dashboard()['unread_notifications']);
    }

    public function test_a_new_provider_sees_zeros_and_the_verified_flag(): void
    {
        $dashboard = $this->dashboard();

        $this->assertSame('Red Sea Divers', $dashboard['business_name']);
        $this->assertTrue($dashboard['is_verified']);
        $this->assertEquals(0, $dashboard['rating']);
        $this->assertSame(0, $dashboard['reviews_count']);
        $this->assertEquals(['gross' => 0, 'net' => 0], $dashboard['month_revenue']);
        $this->assertEquals(0, $dashboard['available_balance']);
        $this->assertEquals(0, $dashboard['pending_balance']);
        $this->assertSame([0, 0, []], [$dashboard['month_bookings'], $dashboard['needs_action'], $dashboard['todays_bookings']]);
    }

    public function test_another_providers_money_and_bookings_are_not_yours(): void
    {
        $this->bookEverything();
        $other = $this->approvedProvider(['commission_rate' => 0]);
        $this->makePayment($this->makeBooking($other, $this->customerNamed('Someone Else'), '2026-11-15', 'completed', 777), 777);

        $this->assertEquals(['gross' => 3000, 'net' => 2610], $this->dashboard()['month_revenue']);
        $this->assertEquals(['gross' => 777, 'net' => 777], $this->dashboard($other)['month_revenue']);
        $this->assertSame(1, $this->dashboard($other)['month_bookings']);
    }
}
