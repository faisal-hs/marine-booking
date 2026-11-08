<?php

namespace Tests\Concerns;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use App\Services\Wallet\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helpers for the provider app API tests: an approved provider with something bookable, bookings and payments inserted EXACTLY as a test
 * needs them (a completed trip last month, a refund, a cancellation 60 days ago), and one way to call the API as someone in particular.
 */
trait BuildsProviderAppScenarios
{
    use BuildsProviderScenarios;

    /**
     * @return array{user: User, provider: Provider, token: string, activity: Activity, option: ActivityBookingOption, period: int}
     */
    protected function approvedProvider(array $providerAttributes = []): array
    {
        [$user, $provider, $token] = $this->makeProvider('verified');
        $provider->update(array_merge(['business_name' => 'Red Sea Divers'], $providerAttributes));
        [$activity, $option] = $this->makeBookableActivity($provider, 'published', ['title' => 'Reef Dive']);

        return ['user' => $user, 'provider' => $provider->refresh(), 'token' => $token, 'activity' => $activity, 'option' => $option,
            'period' => $activity->schedulePeriods()->value('id')];
    }

    /** An unapproved provider (pending, rejected ...) and a token for it. */
    protected function unapprovedProvider(string $status = 'pending'): array
    {
        [$user, $provider, $token] = $this->makeProvider($status);

        return ['user' => $user, 'provider' => $provider, 'token' => $token];
    }

    /** A booking inserted exactly as described. Returns the reservation id. */
    protected function makeBooking(array $scenario, Customer $customer, string $date, string $status = 'confirmed', float $total = 1500, array $extra = []): int
    {
        $now = now()->toDateTimeString();

        return DB::table('reservations')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'activity_id' => $scenario['activity']->id,
            'activity_booking_option_id' => $scenario['option']->id,
            'customer_id' => $customer->id,
            'schedule_period_id' => $scenario['period'],
            'reservation_date' => $date,
            'participants' => json_encode(['adult' => 1]),
            'quantity' => null,
            'duration' => null,
            'status' => $status,
            'hold_expires_at' => null,
            'pricing_model' => 'per_person',
            'currency' => config('platform.currency'),
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
            'pricing_breakdown' => json_encode([['participant_type' => 'adult', 'quantity' => 1, 'unit_price' => $total, 'total' => $total]]),
            'confirmed_at' => in_array($status, ['confirmed', 'completed'], true) ? $now : null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $extra));
    }

    /** A succeeded payment of a booking, optionally refunded in part. */
    protected function makePayment(int $reservationId, float $amount, float $refunded = 0): int
    {
        $now = now()->toDateTimeString();

        return DB::table('payments')->insertGetId([
            'uuid' => (string) Str::uuid(), 'reference_id' => 'PAY-'.strtoupper(Str::random(10)), 'reservation_id' => $reservationId, 'gateway' => 'mock',
            'payment_id' => 'mock_'.Str::random(8), 'provider_reference' => 'mock_'.Str::random(8), 'amount' => $amount, 'currency' => config('platform.currency'),
            'status' => $refunded >= $amount ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : 'succeeded'), 'failure_reason' => null, 'refunded_amount' => $refunded,
            'paid_at' => $now, 'raw_payload' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** A customer with a given name (the provider app shows names). */
    protected function customerNamed(string $name, ?string $phone = null): Customer
    {
        [, $customer] = $this->makeCustomer(array_filter(['name' => $name, 'phone' => $phone]));

        return $customer;
    }

    /** Puts money in a provider's wallet: a completed, paid booking (optionally part-refunded), posted to the ledger. Returns the booking id. */
    protected function fundWallet(array $scenario, float $amount = 1000, string $date = '2026-11-10', float $refunded = 0): int
    {
        $id = $this->makeBooking($scenario, $this->customerNamed('Wallet Customer '.Str::random(5)), $date, 'completed', $amount);
        $this->makePayment($id, $amount, $refunded);
        app(LedgerService::class)->syncProvider($scenario['provider']);

        return $id;
    }

    /** A customer's review of an activity (one per customer and activity). */
    protected function makeReview(\App\Models\Activity $activity, \App\Models\Customer $customer, int $rating = 5, ?string $comment = 'A wonderful trip', array $attrs = []): \App\Models\Review
    {
        $review = \App\Models\Review::create(['customer_id' => $customer->id, 'activity_id' => $activity->id, 'rating' => $rating, 'comment' => $comment] + array_diff_key($attrs, ['created_at' => 1]));

        if (isset($attrs['created_at'])) {
            $review->forceFill(['created_at' => $attrs['created_at'], 'updated_at' => $attrs['created_at']])->save();
        }

        return $review;
    }

    /** A bank account as the API stores it. */
    protected function bankAccount(array $overrides = []): array
    {
        return array_merge(['account_holder_name' => 'Red Sea Divers', 'bank_name' => 'Al Rajhi Bank', 'iban' => 'SA0380000000608010167519', 'swift_code' => null, 'verification_status' => 'approved'], $overrides);
    }

    /** One call to the API as someone in particular (a token, or nobody). Not called call()/get(): those belong to PHPUnit's HTTP helpers. */
    protected function hit(?string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $headers = ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []);

        return $this->withHeaders($headers)->json($method, $uri, $data);
    }

    /** Multipart (files). */
    protected function hitWithFiles(?string $token, string $uri, array $data)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $headers = ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []);

        return $this->withHeaders($headers)->post($uri, $data);
    }
}
