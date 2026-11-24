<?php

namespace Tests\Feature\CustomerApp;

use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/**
 * The places of a departure are never over-sold. A booking by persons is counted against the option's `max_participants` for its departure on its day; a booking by units against
 * `max_quantity`. A booking that is waiting to be paid holds its places until its hold runs out, a confirmed one for good, and a cancelled or expired one gives them back.
 *
 * SQLite cannot race, so this is the rules one request after another, and how the lock is kept. The race itself (14 customers at once, on MariaDB) is
 * tests/Concurrency/check_booking_concurrency.py (checks P1 to P5).
 */
class CustomerAppCapacityTest extends TestCase
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
        $this->a['option']->update(['max_participants' => 3]);
        [, , $this->token] = $this->signedInCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function book(int $guests, ?string $token = null, array $over = [])
    {
        return $this->ca($token ?? $this->token, 'POST', '/bookings', $over + ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => $guests,
            'contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '+966501234567', 'payment_method' => 'card', 'simulate' => 'async'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function placesLeft(): ?int
    {
        $day = collect($this->ca($this->token, 'GET', "/activities/{$this->a['activity']->id}/availability?month=2026-11")->json('data.days'))->firstWhere('date', self::NEXT_SATURDAY);

        return $day['slots'][0]['spots_left'];
    }

    private function full(): void
    {
        $this->book(3)->assertCreated();
        $this->assertSame(0, $this->placesLeft());
    }

    public function test_bookings_fill_the_places_and_the_next_one_is_refused(): void
    {
        $this->book(1)->assertCreated();
        $this->book(1)->assertCreated();
        $this->book(1)->assertCreated();

        $this->assertSame(0, $this->placesLeft());
        $this->book(1)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->assertSame(3, Reservation::count());
    }

    public function test_a_party_that_does_not_fit_is_refused_and_one_that_does_is_not(): void
    {
        $this->book(2)->assertCreated();

        $this->book(2)->assertStatus(409);                 // 4 places in a departure of 3
        $this->assertSame(1, $this->placesLeft());         // the refused one took nothing
        $this->book(1)->assertCreated();
        $this->assertSame(2, Reservation::count());
    }

    public function test_the_places_are_per_departure_and_per_day(): void
    {
        $this->full();
        $a = $this->a['activity'];
        $other = $a->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '15:00', 'end_time' => '18:00']);

        $this->book(3, null, ['time' => '15:00'])->assertCreated();                                  // another departure: its own places
        $this->book(3, null, ['date' => '2026-11-28'])->assertCreated();                             // the Saturday after: its own places
        $this->assertSame(3, Reservation::count());
        $this->assertNotNull($other->id);
    }

    public function test_a_booking_waiting_to_be_paid_holds_its_places_until_the_hold_runs_out(): void
    {
        $this->full();
        $this->book(1)->assertStatus(409);

        Carbon::setTestNow(Carbon::now()->addMinutes((int) config('reservations.hold_minutes') - 1));
        $this->book(1)->assertStatus(409);                 // a minute before it runs out: still held

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->assertSame(3, $this->placesLeft());         // the hold ran out: the places are back
        $this->book(2)->assertCreated();
    }

    public function test_a_confirmed_booking_holds_its_places_for_good(): void
    {
        $this->full();
        Reservation::firstOrFail()->update(['status' => 'confirmed', 'confirmed_at' => now(), 'hold_expires_at' => null]);

        // Long after any hold would have run out (but before the departure on the 21st): the places are still taken.
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->assertSame(0, $this->placesLeft());
        $this->book(1)->assertStatus(409);
    }

    public function test_a_cancelled_or_expired_booking_gives_its_places_back(): void
    {
        $this->full();
        $reservation = Reservation::firstOrFail();

        $this->ca($this->token, 'POST', "/bookings/{$reservation->id}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->assertSame(3, $this->placesLeft());

        $second = Reservation::findOrFail($this->book(3)->assertCreated()->json('data.booking_id'));
        $this->assertSame(0, $this->placesLeft());
        $second->update(['status' => 'expired']);
        $this->assertSame(3, $this->placesLeft());
    }

    public function test_a_confirmed_booking_that_is_cancelled_gives_its_places_back_too(): void
    {
        $this->full();
        $reservation = Reservation::firstOrFail();
        $reservation->update(['status' => 'confirmed', 'confirmed_at' => now(), 'hold_expires_at' => null]);

        $this->book(1)->assertStatus(409);
        $reservation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->book(3)->assertCreated();
    }

    public function test_a_booking_by_units_is_held_to_its_stock_the_same_way(): void
    {
        $o = $this->a['option'];
        $o->update(['booking_model' => 'per_unit', 'max_participants' => null, 'min_quantity' => 1, 'max_quantity' => 2]);
        $o->pricingRules()->delete();
        $o->pricingRules()->create(['participant_type' => null, 'price' => 500]);
        $unit = fn (int $q) => $this->ca($this->token, 'POST', '/bookings', ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'quantity' => $q,
            'contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '+966501234567', 'payment_method' => 'card', 'simulate' => 'async'], ['Idempotency-Key' => (string) Str::uuid()]);

        $unit(1)->assertCreated();
        $unit(2)->assertStatus(409);        // 3 units from a stock of 2
        $unit(1)->assertCreated();
        $unit(1)->assertStatus(409);
        $this->assertSame(2, Reservation::count());
    }

    public function test_the_same_booking_sent_twice_with_one_key_is_one_booking_and_takes_its_places_once(): void
    {
        $key = 'one-key';
        $body = ['activity_id' => $this->a['activity']->id, 'date' => self::NEXT_SATURDAY, 'time' => '09:00', 'guests' => 2, 'contact_name' => 'Ahmed Al-Harbi', 'contact_phone' => '+966501234567', 'payment_method' => 'card', 'simulate' => 'async'];

        $first = $this->ca($this->token, 'POST', '/bookings', $body, ['Idempotency-Key' => $key])->assertCreated()->json('data.booking_id');
        $again = $this->ca($this->token, 'POST', '/bookings', $body, ['Idempotency-Key' => $key])->assertSuccessful()->json('data.booking_id');

        $this->assertSame($first, $again);
        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, $this->placesLeft());
    }

    // --- How the lock is kept (the design that stops two bookings taking the last place) ---------------------------------------

    public function test_every_departure_and_day_has_one_lock_row_made_once_whatever_the_number_of_bookings(): void
    {
        $this->book(1)->assertCreated();
        $this->book(1)->assertCreated();
        $this->book(1, null, ['date' => '2026-11-28'])->assertCreated();

        $locks = \DB::table('reservation_capacity_locks')->where('activity_booking_option_id', $this->a['option']->id)->orderBy('reservation_date')->get();

        $this->assertCount(2, $locks, 'One row for each departure and day');
        $this->assertSame([self::NEXT_SATURDAY, '2026-11-28'], $locks->map(fn ($l) => substr((string) $l->reservation_date, 0, 10))->all());
        $this->assertSame([(int) $this->a['activity']->schedulePeriods()->value('id')], $locks->pluck('schedule_period_id')->unique()->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_refused_booking_leaves_nothing_behind_but_the_lock_row(): void
    {
        $this->full();

        $this->book(1)->assertStatus(409);

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, \DB::table('reservation_capacity_locks')->where('activity_booking_option_id', $this->a['option']->id)->count());
    }
}
