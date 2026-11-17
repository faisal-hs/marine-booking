<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** GET /provider/bookings: the provider's bookings, their tabs, filters and paging. Today in these tests is Sunday 15 November 2026. */
class ProviderBookingsApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();
        $fatima = $this->customerNamed('Fatima Al-Zahrani');
        $sara = $this->customerNamed('Sara Al-Qahtani');

        $this->ids = [
            'today_confirmed' => $this->makeBooking($this->a, $fatima, '2026-11-15', 'confirmed', 1800, ['participants' => json_encode(['adult' => 4, 'child' => 2])]),
            'today_pending' => $this->makeBooking($this->a, $sara, '2026-11-15', 'pending', 900),
            'today_cancelled' => $this->makeBooking($this->a, $sara, '2026-11-15', 'cancelled', 900),
            'tomorrow' => $this->makeBooking($this->a, $fatima, '2026-11-16', 'confirmed', 1500),
            'later_pending' => $this->makeBooking($this->a, $sara, '2026-11-20', 'pending', 1500),
            'past_completed' => $this->makeBooking($this->a, $fatima, '2026-11-10', 'completed', 1500),
            'past_expired' => $this->makeBooking($this->a, $sara, '2026-11-12', 'expired', 1500),
            'past_cancelled' => $this->makeBooking($this->a, $sara, '2026-11-11', 'cancelled', 1500),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function list(string $query = ''): array
    {
        return $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings'.$query)->assertOk()->json();
    }

    private function idsOf(array $response): array
    {
        return array_column($response['data']['bookings'], 'id');
    }

    public function test_a_booking_has_exactly_the_specs_fields_in_the_specs_formats(): void
    {
        $first = $this->list('?filter=today')['data']['bookings'][0];

        $this->assertSame(['id', 'customer_name', 'customer_logo', 'activity', 'date_time', 'guests', 'amount', 'status'], array_keys($first));
        $this->assertNull($first['customer_logo'], 'A customer with no picture has a null logo');
        $this->assertSame($this->ids['today_confirmed'], $first['id']);
        $this->assertSame('Fatima Al-Zahrani', $first['customer_name']);
        $this->assertSame('Reef Dive', $first['activity']);
        $this->assertSame('2026-11-15T09:00:00Z', $first['date_time'], 'The day plus the departure time, in UTC ISO-8601');
        $this->assertSame(6, $first['guests'], 'Four adults and two children');
        $this->assertEquals(1800, $first['amount']);
        $this->assertSame('confirmed', $first['status']);
    }

    public function test_the_counts_for_the_tabs_do_not_change_with_the_filter(): void
    {
        $expected = ['today' => 2, 'upcoming' => 2, 'pending' => 2];

        foreach (['', '?filter=today', '?filter=pending', '?status=cancelled', '?from=2026-11-20'] as $query) {
            $this->assertSame($expected, $this->list($query)['data']['counts'], "counts with {$query}");
        }
    }

    public function test_today_means_the_trips_of_today_that_are_still_real_oldest_departure_first(): void
    {
        $this->assertSame([$this->ids['today_confirmed'], $this->ids['today_pending']], $this->idsOf($this->list('?filter=today')), 'Cancelled is not a trip of today');
    }

    public function test_upcoming_means_later_days_still_to_serve_soonest_first(): void
    {
        $this->assertSame([$this->ids['tomorrow'], $this->ids['later_pending']], $this->idsOf($this->list('?filter=upcoming')));
    }

    public function test_pending_means_waiting_for_action_on_any_day(): void
    {
        $this->assertSame([$this->ids['today_pending'], $this->ids['later_pending']], $this->idsOf($this->list('?filter=pending')));
    }

    public function test_all_lists_todays_trips_first_then_everything_else_newest_trip_first_and_is_the_default(): void
    {
        $all = $this->idsOf($this->list('?filter=all'));

        $this->assertCount(8, $all);
        // Today's three (the newest booking first), then the rest by trip date, newest first: the 20th, the 16th, the 12th, the 11th, the 10th.
        $this->assertSame([$this->ids['today_cancelled'], $this->ids['today_pending'], $this->ids['today_confirmed'], $this->ids['later_pending'], $this->ids['tomorrow'],
            $this->ids['past_expired'], $this->ids['past_cancelled'], $this->ids['past_completed']], $all);
        $this->assertSame($all, $this->idsOf($this->list()), 'The same with no filter at all');
    }

    public function test_today_comes_first_with_a_status_filter_a_range_and_across_pages(): void
    {
        $this->assertSame([$this->ids['today_pending'], $this->ids['later_pending']], $this->idsOf($this->list('?filter=all&status=pending')), 'Today\'s pending, then the later one');
        $this->assertSame([$this->ids['today_cancelled'], $this->ids['past_cancelled']], $this->idsOf($this->list('?status=cancelled')));
        $this->assertSame([$this->ids['today_cancelled'], $this->ids['today_pending'], $this->ids['today_confirmed'], $this->ids['tomorrow']], $this->idsOf($this->list('?from=2026-11-15&to=2026-11-16')));

        // One list, cut into pages of three: the pages join up in the same order, with nothing repeated or lost.
        $pages = array_merge($this->idsOf($this->list('?per_page=3&page=1')), $this->idsOf($this->list('?per_page=3&page=2')), $this->idsOf($this->list('?per_page=3&page=3')));
        $this->assertSame($this->idsOf($this->list()), $pages);
    }

    public function test_with_no_trips_today_it_is_simply_newest_first(): void
    {
        DB::table('reservations')->where('reservation_date', '2026-11-15')->delete();

        $all = $this->idsOf($this->list('?filter=all'));

        $this->assertSame([$this->ids['later_pending'], $this->ids['tomorrow'], $this->ids['past_expired'], $this->ids['past_cancelled'], $this->ids['past_completed']], $all);
    }

    public function test_the_other_tabs_keep_their_own_order(): void
    {
        // Today's soonest departure first, upcoming soonest first: today-first is the order of "all" only.
        $this->assertSame([$this->ids['today_confirmed'], $this->ids['today_pending']], $this->idsOf($this->list('?filter=today')));
        $this->assertSame([$this->ids['tomorrow'], $this->ids['later_pending']], $this->idsOf($this->list('?filter=upcoming')));
    }

    public function test_a_booking_carries_the_customers_picture_as_a_url(): void
    {
        $fatima = Reservation::find($this->ids['today_confirmed'])->customer;
        $fatima->user->forceFill(['avatar_path' => 'avatars/fatima.png'])->save();

        $bookings = collect($this->list()['data']['bookings'])->keyBy('id');

        $url = Storage::disk('public')->url('avatars/fatima.png');
        $this->assertSame($url, $bookings[$this->ids['today_confirmed']]['customer_logo']);
        $this->assertSame($url, $bookings[$this->ids['tomorrow']]['customer_logo'], 'Her other booking too');
        $this->assertNull($bookings[$this->ids['today_pending']]['customer_logo'], 'Another customer has none');
    }

    public function test_the_status_filter_uses_exactly_the_five_booking_statuses_and_combines_with_the_tab(): void
    {
        $this->assertEqualsCanonicalizing([$this->ids['today_cancelled'], $this->ids['past_cancelled']], $this->idsOf($this->list('?status=cancelled')));
        $this->assertSame([$this->ids['past_expired']], $this->idsOf($this->list('?status=expired')));
        $this->assertSame([$this->ids['past_completed']], $this->idsOf($this->list('?filter=all&status=completed')));
        $this->assertSame([$this->ids['today_pending']], $this->idsOf($this->list('?filter=today&status=pending')));

        foreach (['rejected', 'declined', 'active'] as $notAStatus) {
            $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings?status='.$notAStatus)->assertStatus(422);
        }

        // An empty parameter means no filter: apps send them.
        $this->assertCount(8, $this->idsOf($this->list('?status=&filter=&from=&to=&per_page=&page=')));
    }

    public function test_from_and_to_bound_the_trip_day_inclusively(): void
    {
        $found = $this->idsOf($this->list('?from=2026-11-12&to=2026-11-16'));

        $this->assertEqualsCanonicalizing([$this->ids['past_expired'], $this->ids['today_confirmed'], $this->ids['today_pending'], $this->ids['today_cancelled'], $this->ids['tomorrow']], $found);
        $this->assertSame([$this->ids['later_pending']], $this->idsOf($this->list('?from=2026-11-17')));
    }

    public function test_bad_filters_and_dates_are_validation_errors_in_the_specs_shape(): void
    {
        foreach (['?filter=yesterday', '?from=15-11-2026', '?to=2026-13-40', '?from=2026-11-20&to=2026-11-10', '?per_page=0', '?page=0'] as $query) {
            $this->hit($this->a['token'], 'GET', '/api/v1/provider/bookings'.$query)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonPath('status', false);
        }
    }

    public function test_a_provider_sees_only_their_own_bookings(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->makeBooking($other, $this->customerNamed('Someone Else'), '2026-11-15', 'confirmed', 700);

        $mine = $this->list('?per_page=100');
        $this->assertNotContains($theirs, $this->idsOf($mine));
        $this->assertSame(8, $mine['meta']['total']);
        $this->assertSame(['today' => 2, 'upcoming' => 2, 'pending' => 2], $mine['data']['counts'], 'Nor are they counted');

        $theirView = $this->hit($other['token'], 'GET', '/api/v1/provider/bookings')->assertOk()->json();
        $this->assertSame([$theirs], $this->idsOf($theirView));
        $this->assertSame(1, $theirView['meta']['total']);
    }

    public function test_a_deleted_booking_is_not_listed(): void
    {
        DB::table('reservations')->where('id', $this->ids['tomorrow'])->update(['deleted_at' => now()]);

        $this->assertNotContains($this->ids['tomorrow'], $this->idsOf($this->list('?per_page=100')));
    }

    public function test_guests_come_from_the_participants_or_the_quantity(): void
    {
        $unit = $this->makeBooking($this->a, $this->customerNamed('Unit Booker'), '2026-12-01', 'confirmed', 300, ['participants' => null, 'quantity' => 3]);
        $trip = $this->makeBooking($this->a, $this->customerNamed('Trip Booker'), '2026-12-02', 'confirmed', 300, ['participants' => null, 'quantity' => null]);

        $byId = collect($this->list('?per_page=100')['data']['bookings'])->keyBy('id');

        $this->assertSame(3, $byId[$unit]['guests']);
        $this->assertSame(1, $byId[$trip]['guests'], 'A whole trip or group counts as one');
    }
}
