<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Payment;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** Tabby and Tamara: shown on the activity page, and accepted for a booking only where the provider turned them on for that activity. */
class CustomerAppPaymentMethodsTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private array $a;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->a = $this->approvedProvider();
        [, , $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pay(string $method, ?array $scenario = null)
    {
        $s = $scenario ?? $this->a;

        return $this->ca($this->token, 'POST', '/bookings', ['activity_id' => $s['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 1, 'contact_name' => 'Ahmed',
            'contact_phone' => '+966501234567', 'payment_method' => $method], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_the_activity_page_says_which_buy_now_pay_later_methods_it_accepts(): void
    {
        $page = fn () => $this->ca(null, 'GET', "/activities/{$this->a['activity']->id}")->json('data.allowed_payment_methods');

        $this->assertSame(['tabby' => false, 'tamara' => false], $page());

        $this->a['activity']->update(['allows_tabby' => true]);
        $this->assertSame(['tabby' => true, 'tamara' => false], $page());

        $this->a['activity']->update(['allows_tamara' => true, 'allows_tabby' => false]);
        $this->assertSame(['tabby' => false, 'tamara' => true], $page());
    }

    public function test_tabby_and_tamara_are_refused_where_the_provider_did_not_turn_them_on(): void
    {
        foreach (['tabby', 'tamara'] as $method) {
            $r = $this->pay($method)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('payment_method');
            $this->assertStringContainsString(ucfirst($method), $r->json('errors.payment_method.0'));
        }

        $this->assertSame([0, 0], [Reservation::count(), Payment::count()], 'Nothing is booked or charged');
    }

    public function test_each_is_accepted_only_where_it_was_turned_on_and_the_booking_remembers_the_method(): void
    {
        $this->a['activity']->update(['allows_tabby' => true]);

        $this->pay('tamara')->assertStatus(422);
        $this->pay('tabby')->assertCreated();
        $this->assertSame('tabby', Reservation::firstOrFail()->payment_method);

        $this->a['activity']->update(['allows_tamara' => true]);
        $this->pay('tamara')->assertCreated();
        $this->assertSame(['tabby', 'tamara'], Reservation::orderBy('id')->pluck('payment_method')->all());
    }

    public function test_the_choice_is_per_activity(): void
    {
        $second = $this->moreActivity($this->a, 'Sunset Cruise', 800);
        $this->a['activity']->update(['allows_tabby' => true]);
        $secondScenario = ['activity' => $second, 'option' => $second->bookingOptions()->first()];

        $this->pay('tabby')->assertCreated();
        $this->pay('tabby', $secondScenario)->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_turning_it_off_again_stops_new_bookings_with_it(): void
    {
        $this->a['activity']->update(['allows_tabby' => true]);
        $this->pay('tabby')->assertCreated();

        $this->a['activity']->update(['allows_tabby' => false]);

        $this->pay('tabby')->assertStatus(422);
        $this->assertSame(1, Reservation::count(), 'The booking already made is left alone');
    }

    public function test_card_apple_pay_and_mada_never_depend_on_the_choice_and_other_methods_are_unknown(): void
    {
        foreach (['card', 'apple_pay', 'mada'] as $method) {
            $this->pay($method)->assertCreated();
        }
        foreach (['paypal', 'cash', 'TABBY', ''] as $bad) {
            $this->pay($bad)->assertStatus(422)->assertJsonValidationErrors('payment_method');
        }

        $this->assertSame(3, Reservation::count());
    }
}
