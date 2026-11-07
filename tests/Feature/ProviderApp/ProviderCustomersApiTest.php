<?php

namespace Tests\Feature\ProviderApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/customers: who has booked, with a tier. Scores (caps: spend 5000, bookings 10, recent 3; weights 40/30/20/10; "today" is 15 Nov 2026):
 *
 *   Fatima  6 completed, 5400 spent, 3 booked recently, no cancellation   40 + 18 + 20 + 10 = 88  vip
 *   Noor    5 completed, 2500 spent, 3 booked recently, no cancellation   20 + 15 + 20 + 10 = 65  regular
 *   Layla   as Noor, but cancelled one 10 days ago                         20 + 15 + 20 +  0 = 55  regular
 *   Sara    2 completed,  600 spent, none recent, no cancellation          4.8 + 6 + 0 + 10 = 20.8 -> 21  normal
 *   Omar    only a pending and a cancelled booking                         not a customer yet: not listed
 */
class ProviderCustomersApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider();

        $recent = ['created_at' => '2026-11-10 10:00:00'];
        $old = ['created_at' => '2026-06-01 10:00:00'];

        $this->customers = [
            'Fatima' => $fatima = $this->customerNamed('Fatima Al-Zahrani', '01011112222'),
            'Noor' => $noor = $this->customerNamed('Noor Hassan', '01033334444'),
            'Layla' => $layla = $this->customerNamed('Layla Omar', '01055556666'),
            'Sara' => $sara = $this->customerNamed('Sara Al-Qahtani', '01077778888'),
            'Omar' => $omar = $this->customerNamed('Omar Khaled', '01099990000'),
        ];

        foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-10-20'] as $i => $date) {
            $this->makeBooking($this->a, $fatima, $date, 'completed', 900, $i < 3 ? $recent : $old);
        }
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $i => $date) {
            $this->makeBooking($this->a, $noor, $date, 'completed', 500, $i < 3 ? $recent : $old);
        }
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'] as $i => $date) {
            $this->makeBooking($this->a, $layla, $date, 'completed', 500, $i < 3 ? $recent : $old);
        }
        $this->makeBooking($this->a, $layla, '2026-11-20', 'cancelled', 500, ['cancelled_at' => '2026-11-05 10:00:00', 'created_at' => '2026-11-01 10:00:00']);
        foreach (['2026-06-02', '2026-06-09'] as $date) {
            $this->makeBooking($this->a, $sara, $date, 'completed', 300, $old);
        }
        $this->makeBooking($this->a, $omar, '2026-11-25', 'pending', 700);
        $this->makeBooking($this->a, $omar, '2026-11-26', 'cancelled', 700, ['cancelled_at' => '2026-11-10 10:00:00']);
    }

    private array $customers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function list(string $query = '', ?array $as = null): array
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/customers'.$query)->assertOk()->json();
    }

    private function names(array $response): array
    {
        return array_map(fn (string $name) => explode(' ', $name)[0], array_column($response['data']['customers'], 'name'));
    }

    public function test_customers_come_with_their_score_and_tier_best_first(): void
    {
        $response = $this->list();

        $this->assertSame(['Fatima', 'Noor', 'Layla', 'Sara'], $this->names($response), 'Omar has no confirmed or completed booking, so he is not a customer yet');
        $this->assertSame([88, 65, 55, 21], array_column($response['data']['customers'], 'vip_score'));
        $this->assertSame(['vip', 'regular', 'regular', 'normal'], array_column($response['data']['customers'], 'tier'));
    }

    public function test_a_customer_has_exactly_the_specs_fields_and_no_contact_details(): void
    {
        $fatima = $this->list()['data']['customers'][0];

        $this->assertSame(['id', 'name', 'bookings_count', 'total_spent', 'last_booking', 'tier', 'vip_score'], array_keys($fatima));
        $this->assertSame($this->customers['Fatima']->id, $fatima['id']);
        $this->assertSame(6, $fatima['bookings_count']);
        $this->assertEquals(5400, $fatima['total_spent']);
        $this->assertSame('2026-10-20T00:00:00Z', $fatima['last_booking']);
        $this->assertStringNotContainsString('0101111', json_encode($this->list()), 'The phone number must not be returned');
    }

    public function test_the_summary_counts_all_customers_and_does_not_change_with_the_filters(): void
    {
        $expected = ['total' => 4, 'vip' => 1, 'new_this_month' => 1];   // Noor's first booking is this month

        foreach (['', '?tier=regular', '?q=sara', '?tier=normal&q=zzz'] as $query) {
            $this->assertSame($expected, $this->list($query)['data']['summary'], $query);
        }
    }

    public function test_the_tier_filter_narrows_the_list(): void
    {
        $this->assertSame(['Noor', 'Layla'], $this->names($this->list('?tier=regular')));
        $this->assertSame(['Fatima'], $this->names($this->list('?tier=vip')));
        $this->assertSame(['Sara'], $this->names($this->list('?tier=normal')));
        $this->assertCount(4, $this->list('?tier=')['data']['customers'], 'An empty tier is no filter');
        $this->hit($this->a['token'], 'GET', '/api/v1/provider/customers?tier=gold')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_search_matches_a_name_or_a_phone_and_treats_percent_and_underscore_as_plain_characters(): void
    {
        $this->assertSame(['Fatima'], $this->names($this->list('?q=fatima')));
        $this->assertSame(['Sara'], $this->names($this->list('?q=Qahtani')));
        $this->assertSame(['Noor'], $this->names($this->list('?q=0103333')), 'By phone, though the phone is never returned');
        $this->assertSame([], $this->names($this->list('?q=%25')), 'A percent sign is not a wildcard that matches everyone');
        $this->assertSame([], $this->names($this->list('?q=_')), 'Nor is an underscore');
        $this->assertCount(4, $this->list('?q=')['data']['customers']);
    }

    public function test_a_cancellation_in_the_last_90_days_costs_ten_points(): void
    {
        $byName = collect($this->list()['data']['customers'])->keyBy(fn ($c) => explode(' ', $c['name'])[0]);

        $this->assertSame(10, $byName['Noor']['vip_score'] - $byName['Layla']['vip_score']);
    }

    public function test_an_old_cancellation_is_forgiven(): void
    {
        DB::table('reservations')->where('customer_id', $this->customers['Layla']->id)->where('status', 'cancelled')->update(['cancelled_at' => '2026-06-01 10:00:00']);

        $this->assertSame(65, collect($this->list()['data']['customers'])->firstWhere('id', $this->customers['Layla']->id)['vip_score']);
    }

    public function test_the_thresholds_are_configurable(): void
    {
        config(['provider_app.customer_tiers.thresholds' => ['vip' => 60, 'regular' => 20]]);

        $this->assertSame(['vip', 'vip', 'regular', 'regular'], array_column($this->list()['data']['customers'], 'tier'), 'Noor (65) is now vip, and Sara (21) regular');
    }

    public function test_paging_uses_page_and_per_page_with_the_specs_meta(): void
    {
        $second = $this->list('?per_page=2&page=2');

        $this->assertSame(['Layla', 'Sara'], $this->names($second));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'total' => 4], $second['meta']);
    }

    public function test_a_customer_who_only_has_a_future_confirmed_booking_is_listed_with_nothing_spent_yet(): void
    {
        $newcomer = $this->customerNamed('Hala Newcomer');
        $this->makeBooking($this->a, $newcomer, '2026-11-28', 'confirmed', 800, ['created_at' => '2026-11-14 10:00:00']);

        $hala = collect($this->list()['data']['customers'])->firstWhere('name', 'Hala Newcomer');

        $this->assertSame(1, $hala['bookings_count']);
        $this->assertEquals(0, $hala['total_spent'], 'Only completed trips count as spend');
        $this->assertSame('normal', $hala['tier']);
        $this->assertSame(5, $this->list()['data']['summary']['total'], 'She is a customer now, so she is in the total');
    }

    public function test_you_see_only_your_own_customers_and_only_your_own_history_with_them(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->customerNamed('Someone Else');
        $this->makeBooking($other, $theirs, '2026-10-01', 'completed', 9999);
        // Fatima also booked with the other provider: that is not part of YOUR history with her.
        $this->makeBooking($other, $this->customers['Fatima'], '2026-10-02', 'completed', 4000);

        $mine = $this->list();
        $this->assertNotContains('Someone', $this->names($mine));
        $fatima = $mine['data']['customers'][0];
        $this->assertSame(6, $fatima['bookings_count']);
        $this->assertEquals(5400, $fatima['total_spent']);

        $theirView = $this->list('', $other);
        $this->assertSame(['Someone', 'Fatima'], $this->names($theirView));
        $this->assertEquals(4000, collect($theirView['data']['customers'])->firstWhere('name', 'Fatima Al-Zahrani')['total_spent']);
    }
}
