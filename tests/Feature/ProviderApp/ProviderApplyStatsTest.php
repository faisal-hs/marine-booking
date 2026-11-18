<?php

namespace Tests\Feature\ProviderApp;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/apply answers with the application, the provider's own information and their statistics. Today is Sunday 15 November 2026 and the provider's
 * commission is 10%.
 */
class ProviderApplyStatsTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        $this->apply($this->a, 'approved');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function apply(array $p, string $status): void
    {
        DB::table('provider_requests')->insert(['uuid' => (string) Str::uuid(), 'user_id' => $p['user']->id, 'provider_id' => $p['provider']->id, 'source' => 'registration', 'status' => $status,
            'submitted_at' => '2026-10-20 11:00:00', 'reviewed_at' => $status === 'pending' ? null : '2026-10-21 09:00:00', 'created_at' => '2026-10-20 11:00:00', 'updated_at' => '2026-10-20 11:00:00']);
    }

    private function show(?array $as = null): array
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', '/api/v1/provider/apply')->assertOk()->json('data');
    }

    // --- The shape --------------------------------------------------------------------------------------------------

    public function test_the_answer_is_the_application_then_the_provider_then_the_statistics(): void
    {
        $d = $this->show();

        $this->assertSame(['application_id', 'status', 'note', 'submitted_at', 'reviewed_at', 'can_resubmit', 'provider', 'stats'], array_keys($d));
        $this->assertSame(['approved', false], [$d['status'], $d['can_resubmit']]);
    }

    public function test_the_provider_part_is_the_same_information_as_the_profile(): void
    {
        $profile = $this->hit($this->a['token'], 'GET', '/api/v1/provider/profile')->assertOk()->json('data');
        $provider = $this->show()['provider'];

        foreach (['id', 'uuid', 'name', 'email', 'phone', 'business_name', 'slug', 'bio', 'logo', 'address', 'city', 'area', 'business_type', 'marina_name', 'contact_phone', 'contact_email', 'category_ids',
            'lat', 'lng', 'verification_status', 'rejection_reason', 'status'] as $field) {
            $this->assertArrayHasKey($field, $provider, $field);
            $this->assertEquals($profile[$field], $provider[$field], $field);
        }
        $this->assertSame($this->a['provider']->business_name, $provider['business_name']);
    }

    public function test_the_statistics_have_these_fields(): void
    {
        $this->assertSame(['activities_count', 'active_activities_count', 'bookings_count', 'completed_bookings_count', 'upcoming_bookings_count', 'customers_count', 'rating', 'reviews_count',
            'gross_revenue', 'available_balance', 'pending_balance'], array_keys($this->show()['stats']));
    }

    // --- Each statistic ------------------------------------------------------------------------------------------------

    public function test_an_applicant_with_nothing_yet_gets_zeros(): void
    {
        $p = $this->unapprovedProvider('pending');
        $this->apply($p, 'pending');

        $stats = $this->show($p)['stats'];

        $this->assertEquals(['activities_count' => 0, 'active_activities_count' => 0, 'bookings_count' => 0, 'completed_bookings_count' => 0, 'upcoming_bookings_count' => 0, 'customers_count' => 0,
            'rating' => 0, 'reviews_count' => 0, 'gross_revenue' => 0, 'available_balance' => 0, 'pending_balance' => 0], $stats);
        $this->assertSame(0, $stats['rating'], 'No reviews is a rating of 0, as on the dashboard');
    }

    public function test_the_activity_counts_are_those_of_the_activity_list(): void
    {
        $this->makeActivity($this->a['provider'], 'draft');
        $this->makeActivity($this->a['provider'], 'inactive');
        $this->makeActivity($this->a['provider'], 'published');

        $stats = $this->show()['stats'];
        $counts = $this->hit($this->a['token'], 'GET', '/api/v1/provider/activities')->json('counts');

        $this->assertSame([4, 2], [$stats['activities_count'], $stats['active_activities_count']]);
        $this->assertSame([$counts['all'], $counts['active']], [$stats['activities_count'], $stats['active_activities_count']]);
    }

    public function test_bookings_are_the_confirmed_and_completed_ones_and_the_customers_who_made_them(): void
    {
        $fatima = $this->customerNamed('Fatima');
        $noura = $this->customerNamed('Noura');
        foreach ([[$fatima, '2026-11-01', 'completed'], [$fatima, '2026-11-02', 'completed'], [$noura, '2026-11-10', 'completed'], [$noura, '2026-11-20', 'confirmed'], [$fatima, '2026-11-15', 'confirmed']] as [$c, $d, $s]) {
            $this->makeBooking($this->a, $c, $d, $s);
        }
        foreach (['pending', 'cancelled', 'expired'] as $status) {
            $this->makeBooking($this->a, $this->customerNamed("Other {$status}"), '2026-11-20', $status);
        }

        $stats = $this->show()['stats'];

        $this->assertSame([5, 3, 2, 2], [$stats['bookings_count'], $stats['completed_bookings_count'], $stats['upcoming_bookings_count'], $stats['customers_count']], 'Today\'s trip is still to come');
    }

    public function test_the_rating_is_the_average_of_the_visible_reviews_to_one_decimal(): void
    {
        foreach ([5, 5, 4, 4, 3] as $i => $stars) {
            $this->makeReview($this->a['activity'], $this->customerNamed("Reviewer {$i}"), $stars, 'x');
        }
        $this->makeReview($this->a['activity'], $this->customerNamed('Hidden'), 1, 'rude', ['is_hidden' => true, 'hidden_at' => now()]);

        $stats = $this->show()['stats'];
        $dashboard = $this->hit($this->a['token'], 'GET', '/api/v1/provider/dashboard')->json('data');

        $this->assertEquals([4.2, 5], [$stats['rating'], $stats['reviews_count']], '21 / 5 = 4.2; the hidden one-star review is not counted');
        $this->assertEquals([$dashboard['rating'], $dashboard['reviews_count']], [$stats['rating'], $stats['reviews_count']], 'The same as the dashboard');
    }

    public function test_revenue_is_the_ledgers_gross_and_the_balances_are_the_wallets(): void
    {
        $this->fundWallet($this->a, 1000, '2026-11-01');
        $this->fundWallet($this->a, 2500, '2026-11-10');

        $stats = $this->show()['stats'];

        $this->assertEquals(3500, $stats['gross_revenue'], 'The price of the trips, before the 10% commission');
        $this->assertEquals(3150, $stats['available_balance'], 'After the commission, all of it settled');
        $this->assertEquals(0, $stats['pending_balance']);
    }

    public function test_a_trip_that_has_not_settled_yet_is_pending_not_available(): void
    {
        config(['wallet.settlement_delay_days' => 3]);
        $this->fundWallet($this->a, 1000, '2026-11-14');   // settles on the 17th
        $this->fundWallet($this->a, 2000, '2026-11-01');

        $stats = $this->show()['stats'];

        $this->assertEquals([1800, 900], [$stats['available_balance'], $stats['pending_balance']]);
    }

    public function test_another_providers_figures_are_not_counted(): void
    {
        $other = $this->approvedProvider();
        $this->makeBooking($other, $this->customerNamed('Theirs'), '2026-11-01', 'completed');
        $this->makeReview($other['activity'], $this->customerNamed('Their reviewer'), 1, 'bad');
        $this->fundWallet($other, 9999, '2026-11-01');

        $stats = $this->show()['stats'];

        $this->assertEquals([1, 0, 0, 0, 0, 0], [$stats['activities_count'], $stats['bookings_count'], $stats['reviews_count'], $stats['gross_revenue'], $stats['available_balance'], $stats['customers_count']]);
    }

    // --- What has not changed ------------------------------------------------------------------------------------------------

    public function test_with_no_application_it_is_still_not_found_and_shows_nothing(): void
    {
        $p = $this->unapprovedProvider('pending');

        $r = $this->hit($p['token'], 'GET', '/api/v1/provider/apply')->assertNotFound();

        $this->assertSame(['status', 'code', 'message', 'errors'], array_keys($r->json()));
    }

    public function test_a_staff_token_and_nobody_can_read_it(): void
    {
        // (A customer who has applied can: see ProviderBecomingAProviderApiTest.)
        $staff = User::factory()->admin()->create();

        $this->hit($this->tokenFor($staff, 'admin'), 'GET', '/api/v1/provider/apply')->assertForbidden();
        $this->hit(null, 'GET', '/api/v1/provider/apply')->assertUnauthorized();
    }
}
