<?php

namespace Tests\Feature\ProviderApp;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\Reservation;
use App\Notifications\Reservations\Provider\BookingCancelledProviderNotification;
use App\Notifications\Reservations\ReservationCancelledNotification;
use App\Services\Payments\MockPaymentGateway;
use App\Services\PaymentService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * POST /provider/bookings/{id}/cancel. Bookings confirm themselves when paid, so a provider does not accept or decline: they can CANCEL a confirmed
 * booking, with a reason. It is logged as the provider's doing, the customer is refunded in full and told, and it is all or nothing.
 */
class ProviderBookingCancellationApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private $customerUser;

    private $customer;

    private Reservation $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();
        [$this->customerUser, $this->customer] = $this->makeCustomer(['name' => 'Fatima Al-Zahrani']);

        // A real booking, really paid: it confirmed itself.
        $this->booking = $this->bookActivity($this->customer, $this->a['activity'], $this->a['option'], \Illuminate\Support\Carbon::parse('2026-11-21'));
        $this->payFor($this->booking);
        $this->booking->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    private function cancel(string $reason = 'Boat under maintenance', ?array $as = null, $id = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'POST', '/api/v1/provider/bookings/'.($id ?? $this->booking->id).'/cancel', ['reason' => $reason]);
    }

    private function untouched(): void
    {
        $this->booking->refresh();
        $this->assertSame('confirmed', $this->booking->status->value);
        $this->assertNull($this->booking->cancelled_at);
        $this->assertNull($this->booking->cancellation_source);
    }

    // --- The cancellation ---------------------------------------------------------------------------------------------

    public function test_a_provider_cancels_a_confirmed_booking_and_the_response_says_what_happened(): void
    {
        $this->assertSame('confirmed', $this->booking->status->value, 'Paying confirmed it, with no acceptance by the provider');

        $response = $this->cancel()->assertOk();

        $this->assertSame(['status' => true, 'message' => 'Booking cancelled'], array_intersect_key($response->json(), ['status' => 1, 'message' => 1]));
        $this->assertSame([
            'id' => $this->booking->id,
            'status' => 'cancelled',
            'cancellation_source' => 'provider',
            'cancellation_reason' => 'Boat under maintenance',
            'cancelled_at' => '2026-11-15T12:00:00Z',
            'refunded_amount' => 1500,
        ], $response->json('data'));
    }

    public function test_it_is_logged_as_the_providers_cancellation_and_by_which_account(): void
    {
        $this->cancel()->assertOk();

        $this->booking->refresh();
        $this->assertSame('cancelled', $this->booking->status->value);
        $this->assertSame('provider', $this->booking->cancellation_source->value);
        $this->assertSame($this->a['user']->id, $this->booking->cancelled_by_user_id);
        $this->assertSame('Boat under maintenance', $this->booking->cancellation_reason);
        $this->assertNotNull($this->booking->cancelled_at);
    }

    public function test_the_customer_is_refunded_in_full(): void
    {
        $this->cancel()->assertOk();

        $payment = $this->booking->payments()->first();
        $this->assertSame('refunded', $payment->status->value);
        $this->assertEquals(1500, $payment->refunded_amount);
    }

    public function test_only_what_remains_is_refunded_when_part_was_already_refunded(): void
    {
        $this->booking->payments()->first()->update(['status' => 'partially_refunded', 'refunded_amount' => 500]);

        $this->cancel()->assertOk()->assertJsonPath('data.refunded_amount', 1000);

        $payment = $this->booking->payments()->first();
        $this->assertSame('refunded', $payment->status->value);
        $this->assertEquals(1500, $payment->refunded_amount, 'Never more than was paid');
    }

    public function test_a_confirmed_booking_with_no_payment_on_record_cancels_with_nothing_to_refund(): void
    {
        $id = $this->makeBooking($this->a, $this->customer, '2026-11-22', 'confirmed', 700);

        $this->cancel('No crew available', null, $id)->assertOk()->assertJsonPath('data.refunded_amount', 0);
    }

    public function test_the_customer_is_told_it_was_the_provider_and_that_they_are_refunded_the_provider_is_not_told_of_their_own_action(): void
    {
        $this->cancel()->assertOk();

        $told = $this->customerUser->notifications()->where('type', ReservationCancelledNotification::class)->get();
        $this->assertCount(1, $told);
        $this->assertStringContainsString('Red Sea Divers cancelled your reservation', $told[0]->data['message']);
        $this->assertStringContainsString('refunded in full', $told[0]->data['message']);

        $this->assertSame(0, $this->a['user']->notifications()->where('type', BookingCancelledProviderNotification::class)->count(), 'The provider did this themselves');
    }

    public function test_when_the_customer_cancels_it_is_logged_as_theirs_and_the_provider_is_told(): void
    {
        $this->app['auth']->forgetGuards();
        $this->hit($this->tokenFor($this->customerUser, 'customer'), 'POST', "/api/v1/customer/reservations/{$this->booking->uuid}/cancel", ['reason' => 'Plans changed'])->assertOk();

        $this->booking->refresh();
        $this->assertSame('customer', $this->booking->cancellation_source->value);
        $this->assertSame($this->customerUser->id, $this->booking->cancelled_by_user_id);
        $this->assertSame(1, $this->a['user']->notifications()->where('type', BookingCancelledProviderNotification::class)->count());
        $this->assertSame('customer', $this->hit($this->tokenFor($this->customerUser, 'customer'), 'GET', "/api/v1/customer/reservations/{$this->booking->uuid}")->json('data.cancellation_source'));
    }

    public function test_the_booking_list_shows_it_as_cancelled(): void
    {
        $this->cancel()->assertOk();

        $listed = $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings?status=cancelled')->json('data.bookings');

        $this->assertSame([$this->booking->id], array_column($listed, 'id'));
        $this->assertSame(['today' => 0, 'upcoming' => 0, 'pending' => 0], $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings')->json('data.counts'));
    }

    // --- The reason is mandatory ------------------------------------------------------------------------------------------

    public function test_a_reason_is_required(): void
    {
        foreach ([null, '', '   ', 'ab', str_repeat('x', 501)] as $bad) {
            $response = $this->cancel((string) $bad)->assertStatus(422);

            $this->assertSame('CANCELLATION_REASON_REQUIRED', $response->json('code'), var_export($bad, true));
            $this->assertFalse($response->json('status'));
            $this->assertArrayHasKey('reason', $response->json('errors'));
        }

        $this->hit($this->a['token'], 'POST', "/api/v1/provider/bookings/{$this->booking->id}/cancel", [])->assertStatus(422)->assertJsonPath('code', 'CANCELLATION_REASON_REQUIRED');
        $this->untouched();
        $this->assertSame('succeeded', $this->booking->payments()->first()->status->value, 'And nobody was refunded');
    }

    // --- Only a confirmed booking, before its trip ---------------------------------------------------------------------------

    public function test_only_a_confirmed_booking_can_be_cancelled(): void
    {
        $pending = $this->makeBooking($this->a, $this->customer, '2026-11-25', 'pending');
        $completed = $this->makeBooking($this->a, $this->customer, '2026-11-25', 'completed');
        $expired = $this->makeBooking($this->a, $this->customer, '2026-11-25', 'expired');
        $cancelled = $this->makeBooking($this->a, $this->customer, '2026-11-25', 'cancelled');

        foreach ([$pending, $completed, $expired, $cancelled] as $id) {
            $this->cancel('Not possible', null, $id)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        }
    }

    public function test_it_cannot_be_cancelled_twice_and_the_second_try_refunds_nothing_more(): void
    {
        $this->cancel()->assertOk();
        $this->cancel('Again')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $this->assertEquals(1500, $this->booking->payments()->first()->refunded_amount);
        $this->assertSame('Boat under maintenance', $this->booking->refresh()->cancellation_reason, 'The first reason stands');
    }

    public function test_a_trip_that_has_already_taken_place_cannot_be_cancelled_but_one_today_can(): void
    {
        $past = $this->makeBooking($this->a, $this->customer, '2026-11-14', 'confirmed');
        $today = $this->makeBooking($this->a, $this->customer, '2026-11-15', 'confirmed');

        $this->cancel('Too late', null, $past)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->cancel('Engine failure this morning', null, $today)->assertOk();
    }

    // --- Whose booking it is (your decision: another provider's is simply not found) ---------------------------------------------

    public function test_another_providers_booking_is_not_found_exactly_like_one_that_does_not_exist(): void
    {
        $other = $this->approvedProvider();

        $theirs = $this->cancel('Not yours', $other)->assertNotFound();
        $missing = $this->cancel('Nothing here', $other, 999999)->assertNotFound();
        $text = $this->cancel('Not a number', $other, 'abc')->assertNotFound();

        foreach ([$theirs, $missing, $text] as $response) {
            $this->assertSame('RESOURCE_NOT_FOUND', $response->json('code'));
            $this->assertFalse($response->json('status'));
        }
        $this->assertSame($theirs->json('message'), $missing->json('message'), 'The answer must not tell the two cases apart');
        $this->untouched();
        $this->assertSame('succeeded', $this->booking->payments()->first()->status->value);
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $unapproved = $this->unapprovedProvider();

        $this->cancel('x y z', $unapproved)->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        $this->hit($this->tokenFor($this->customerUser, 'customer'), 'POST', "/api/v1/provider/bookings/{$this->booking->id}/cancel", ['reason' => 'x y z'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'POST', "/api/v1/provider/bookings/{$this->booking->id}/cancel", ['reason' => 'x y z'])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->untouched();
    }

    // --- All or nothing ------------------------------------------------------------------------------------------------------------

    public function test_if_the_refund_cannot_be_made_the_booking_is_not_cancelled(): void
    {
        $gateway = Mockery::mock(MockPaymentGateway::class)->makePartial();
        $gateway->shouldReceive('refund')->andThrow(new \RuntimeException('The payment gateway is down'));
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $this->app->forgetInstance(PaymentService::class);

        $response = $this->cancel()->assertStatus(502);

        $this->assertSame('REFUND_FAILED', $response->json('code'));
        $this->assertStringContainsString('NOT cancelled', $response->json('message'));
        $this->untouched();
        $payment = $this->booking->payments()->first();
        $this->assertSame('succeeded', $payment->status->value);
        $this->assertEquals(0, $payment->refunded_amount);
        $this->assertSame(0, $this->customerUser->notifications()->where('type', ReservationCancelledNotification::class)->count(), 'The customer was not told of a cancellation that did not happen');
    }

    // --- The admin can see it ------------------------------------------------------------------------------------------------------

    public function test_the_admin_reservation_page_says_who_cancelled(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeAdmin();
        $this->cancel()->assertOk();

        // The provider's token from the call above must not travel with the admin's page request.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $page = $this->actingAs($admin, 'web')->get(route('admin.reservations.show', $this->booking))->assertOk();

        $page->assertSee(__('admin.reservations.cancelled_by'));
        $page->assertSee(__('admin.reservations.sources.provider'));
        $page->assertSee('Boat under maintenance');
        $this->assertSame('provider', DB::table('reservations')->where('id', $this->booking->id)->value('cancellation_source'));
    }
}
