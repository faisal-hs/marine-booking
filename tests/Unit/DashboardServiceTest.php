<?php

namespace Tests\Unit;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardService::class);
    }

    protected function makeActivity(string $status = 'published'): Activity
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);

        return Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test',
            'slug' => 'test-' . uniqid(),
            'status' => $status,
        ]);
    }

    protected function makeCustomer(): Customer
    {
        $user = User::factory()->customer()->create();

        return Customer::create(['user_id' => $user->id]);
    }

    protected function makeReservation(Activity $activity, Customer $customer, array $overrides = []): Reservation
    {
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        return Reservation::create(array_merge([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->toDateString(),
            'status' => 'confirmed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 1000,
            'total' => 1000,
            'pricing_breakdown' => [],
        ], $overrides));
    }

    protected function makePayment(Reservation $reservation, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'reservation_id' => $reservation->id,
            'gateway' => 'mock',
            'amount' => 1000,
            'currency' => 'EGP',
            'status' => 'succeeded',
            'paid_at' => now(),
        ], $overrides));
    }

    public function test_user_stats_counts_by_type(): void
    {
        User::factory()->customer()->create();
        User::factory()->customer()->create();
        User::factory()->provider()->create();

        $stats = $this->service->getStats();

        $this->assertEquals(3, $stats['users']['total']);
        $this->assertEquals(2, $stats['users']['customers']);
        $this->assertEquals(1, $stats['users']['providers']);
    }

    public function test_provider_stats_counts_by_verification_status(): void
    {
        $u1 = User::factory()->provider()->create();
        Provider::create(['user_id' => $u1->id, 'business_name' => 'A', 'slug' => 'a-' . uniqid(), 'verification_status' => 'pending']);
        $u2 = User::factory()->provider()->create();
        Provider::create(['user_id' => $u2->id, 'business_name' => 'B', 'slug' => 'b-' . uniqid(), 'verification_status' => 'verified']);

        $stats = $this->service->getStats();

        $this->assertEquals(1, $stats['providers']['pending_verification']);
        $this->assertEquals(1, $stats['providers']['verified']);
    }

    public function test_activity_stats_counts_published_and_pending_review(): void
    {
        $this->makeActivity('published');
        $this->makeActivity('pending_review');
        $this->makeActivity('draft');

        $stats = $this->service->getStats();

        $this->assertEquals(1, $stats['activities']['published']);
        $this->assertEquals(1, $stats['activities']['pending_review']);
    }

    public function test_reservation_stats_counts_today_and_upcoming(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();

        $this->makeReservation($activity, $customer, ['status' => 'confirmed', 'reservation_date' => now()->toDateString()]);
        $this->makeReservation($activity, $customer, ['status' => 'confirmed', 'reservation_date' => now()->addDays(3)->toDateString()]);
        $this->makeReservation($activity, $customer, ['status' => 'pending', 'reservation_date' => now()->toDateString()]);

        $stats = $this->service->getStats();

        $this->assertEquals(3, $stats['reservations']['total']);
        $this->assertEquals(1, $stats['reservations']['today']);
        $this->assertEquals(2, $stats['reservations']['upcoming_7_days']);
        $this->assertEquals(1, $stats['reservations']['pending']);
    }

    public function test_net_revenue_subtracts_refunds_from_succeeded_payments(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        $reservationA = $this->makeReservation($activity, $customer);
        $reservationB = $this->makeReservation($activity, $customer);

        $this->makePayment($reservationA, ['amount' => 1000, 'status' => 'succeeded']);
        $this->makePayment($reservationB, ['amount' => 500, 'status' => 'partially_refunded', 'refunded_amount' => 200]);

        $stats = $this->service->getStats();

        // (1000 + 500) - 200 refunded = 1300
        $this->assertEquals(1300.0, $stats['payments']['net_revenue']);
        $this->assertEquals(200.0, $stats['payments']['total_refunded']);
    }

    public function test_fully_refunded_payment_nets_to_zero_contribution(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        $reservation = $this->makeReservation($activity, $customer);

        $this->makePayment($reservation, ['amount' => 1000, 'status' => 'refunded', 'refunded_amount' => 1000]);

        $stats = $this->service->getStats();

        $this->assertEquals(0.0, $stats['payments']['net_revenue']);
    }

    public function test_pending_and_failed_payments_do_not_count_toward_revenue(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        $reservationA = $this->makeReservation($activity, $customer);
        $reservationB = $this->makeReservation($activity, $customer);

        $this->makePayment($reservationA, ['amount' => 1000, 'status' => 'pending', 'paid_at' => null]);
        $this->makePayment($reservationB, ['amount' => 500, 'status' => 'failed', 'paid_at' => null]);

        $stats = $this->service->getStats();

        $this->assertEquals(0.0, $stats['payments']['net_revenue']);
        $this->assertEquals(1, $stats['payments']['pending_count']);
    }

    public function test_failed_recent_count_excludes_old_failures(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        $recentReservation = $this->makeReservation($activity, $customer);
        $oldReservation = $this->makeReservation($activity, $customer);

        $recentPayment = $this->makePayment($recentReservation, ['status' => 'failed', 'paid_at' => null]);
        $oldPayment = $this->makePayment($oldReservation, ['status' => 'failed', 'paid_at' => null]);
        $oldPayment->forceFill(['created_at' => now()->subDays(10)])->save();
        $stats = $this->service->getStats();

        $this->assertEquals(1, $stats['payments']['failed_recent_count']);
    }

    public function test_review_stats_averages_only_visible_reviews(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        Review::create(['customer_id' => $customer->id, 'activity_id' => $activity->id, 'rating' => 5, 'is_hidden' => false]);
        Review::create(['customer_id' => $customer->id, 'activity_id' => $this->makeActivity()->id, 'rating' => 1, 'is_hidden' => true]);

        $stats = $this->service->getStats();

        $this->assertEquals(1, $stats['reviews']['total']);
        $this->assertEquals(5.0, $stats['reviews']['average_platform_rating']);
    }

    public function test_recent_reservations_returns_latest_first(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        $older = $this->makeReservation($activity, $customer);
        $newer = $this->makeReservation($activity, $customer);

        $recent = $this->service->recentReservations(5);

        $this->assertEquals($newer->id, $recent->first()->id);
    }

    public function test_recent_payments_and_recent_users_respect_limit(): void
    {
        $activity = $this->makeActivity();
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 3; $i++) {
            $this->makePayment($this->makeReservation($activity, $customer));
        }

        $this->assertCount(2, $this->service->recentPayments(2));
    }
}
