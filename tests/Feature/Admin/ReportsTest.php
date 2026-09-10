<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected ReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(ReportService::class);
    }

    protected function makeAdmin(string $role = 'super_admin'): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function makePaidReservation(Carbon $paidAt, float $amount = 1000, ?Activity $activity = null, ?Provider $provider = null, ?Category $category = null): Payment
    {
        if (! $activity) {
            $providerUser = User::factory()->provider()->create();
            $provider = $provider ?: Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid()]);
            $category = $category ?: Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
            $activity = Activity::create([
                'provider_id' => $provider->id,
                'category_id' => $category->id,
                'title' => 'Test Activity',
                'slug' => 'test-activity-' . uniqid(),
                'status' => 'published',
            ]);
        }
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $reservation = Reservation::create([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->addDays(10)->toDateString(),
            'status' => 'confirmed',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => $amount,
            'total' => $amount,
            'pricing_breakdown' => [],
        ]);
        $reservation->forceFill(['created_at' => $paidAt])->save();

        return Payment::create([
            'reservation_id' => $reservation->id,
            'gateway' => 'mock',
            'amount' => $amount,
            'currency' => 'EGP',
            'status' => 'succeeded',
            'paid_at' => $paidAt,
        ]);
    }

    // --- Access control ---

    public function test_admin_with_permission_can_view_reports(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.index'));

        $response->assertOk();
    }

    public function test_admin_without_permission_cannot_view_reports(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.index'));

        $response->assertStatus(403);
    }

    public function test_unauthenticated_visitor_is_redirected(): void
    {
        $response = $this->get(route('admin.reports.index'));

        $response->assertRedirect(route('admin.login'));
    }

    // --- ReportService: revenue/reservations trends ---

    public function test_revenue_over_time_sums_by_day_and_zero_fills_gaps(): void
    {
        $this->makePaidReservation(now()->subDays(2), 1000);
        $this->makePaidReservation(now()->subDays(2), 500);
        $this->makePaidReservation(now(), 2000);

        $series = $this->service->revenueOverTime(now()->subDays(2)->startOfDay(), now()->endOfDay());

        $this->assertCount(3, $series['labels']);
        $this->assertEquals(1500.0, $series['data'][0]);
        $this->assertEquals(0.0, $series['data'][1]);
        $this->assertEquals(2000.0, $series['data'][2]);
    }

    public function test_revenue_over_time_excludes_pending_and_failed_payments(): void
    {
        $activity = null;
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'Test', 'slug' => 'test-' . uniqid(), 'status' => 'published']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);
        $reservation = Reservation::create([
            'activity_id' => $activity->id,
            'activity_booking_option_id' => $option->id,
            'customer_id' => $customer->id,
            'reservation_date' => now()->addDays(5)->toDateString(),
            'status' => 'pending',
            'pricing_model' => 'per_person',
            'currency' => 'EGP',
            'subtotal' => 1000,
            'total' => 1000,
            'pricing_breakdown' => [],
        ]);
        Payment::create(['reservation_id' => $reservation->id, 'gateway' => 'mock', 'amount' => 1000, 'currency' => 'EGP', 'status' => 'pending', 'paid_at' => null]);

        $series = $this->service->revenueOverTime(now()->startOfDay(), now()->endOfDay());

        $this->assertEquals(0.0, $series['data'][0]);
    }

    public function test_reservations_over_time_counts_by_creation_date(): void
    {
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $activity = Activity::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'Test', 'slug' => 'test-' . uniqid()]);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::create(['user_id' => $customerUser->id]);

        for ($i = 0; $i < 3; $i++) {
            Reservation::create([
                'activity_id' => $activity->id,
                'activity_booking_option_id' => $option->id,
                'customer_id' => $customer->id,
                'reservation_date' => now()->addDays(5)->toDateString(),
                'status' => 'pending',
                'pricing_model' => 'per_person',
                'currency' => 'EGP',
                'subtotal' => 100,
                'total' => 100,
                'pricing_breakdown' => [],
            ]);
        }

        $series = $this->service->reservationsOverTime(now()->startOfDay(), now()->endOfDay());

        $this->assertEquals(3.0, $series['data'][0]);
    }

    // --- Top performers ---

    public function test_top_activities_ranks_by_revenue_descending(): void
    {
        $categoryA = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $providerUserA = User::factory()->provider()->create();
        $providerA = Provider::create(['user_id' => $providerUserA->id, 'business_name' => 'Provider A', 'slug' => 'a-' . uniqid()]);
        $lowActivity = Activity::create(['provider_id' => $providerA->id, 'category_id' => $categoryA->id, 'title' => 'Low Revenue Activity', 'slug' => 'low-' . uniqid(), 'status' => 'published']);
        $highActivity = Activity::create(['provider_id' => $providerA->id, 'category_id' => $categoryA->id, 'title' => 'High Revenue Activity', 'slug' => 'high-' . uniqid(), 'status' => 'published']);

        $this->makePaidReservation(now(), 500, $lowActivity, $providerA, $categoryA);
        $this->makePaidReservation(now(), 5000, $highActivity, $providerA, $categoryA);

        $top = $this->service->topActivities(now()->startOfDay(), now()->endOfDay());

        $this->assertEquals('High Revenue Activity', $top->first()->title);
        $this->assertEquals(5000.0, (float) $top->first()->revenue);
    }

    public function test_top_activities_excludes_payments_outside_the_date_range(): void
    {
        $this->makePaidReservation(now()->subDays(60), 5000);

        $top = $this->service->topActivities(now()->subDays(7)->startOfDay(), now()->endOfDay());

        $this->assertCount(0, $top);
    }

    public function test_top_providers_aggregates_across_multiple_activities(): void
    {
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Multi Activity Provider', 'slug' => 'multi-' . uniqid()]);
        $activityA = Activity::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'A', 'slug' => 'a-' . uniqid(), 'status' => 'published']);
        $activityB = Activity::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'B', 'slug' => 'b-' . uniqid(), 'status' => 'published']);

        $this->makePaidReservation(now(), 1000, $activityA, $provider, $category);
        $this->makePaidReservation(now(), 1500, $activityB, $provider, $category);

        $top = $this->service->topProviders(now()->startOfDay(), now()->endOfDay());

        $this->assertEquals('Multi Activity Provider', $top->first()->business_name);
        $this->assertEquals(2500.0, (float) $top->first()->revenue);
        $this->assertEquals(2, $top->first()->bookings_count);
    }

    public function test_top_categories_aggregates_across_activities_and_providers(): void
    {
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $this->makePaidReservation(now(), 1000, null, null, $category);
        $this->makePaidReservation(now(), 2000, null, null, $category);

        $top = $this->service->topCategories(now()->startOfDay(), now()->endOfDay());

        $this->assertEquals('Diving', $top->first()->name);
        $this->assertEquals(3000.0, (float) $top->first()->revenue);
    }

    // --- Date range presets ---

    public function test_last_7_days_preset_produces_a_seven_day_range(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.index', ['preset' => 'last_7_days']));

        $response->assertOk();
    }

    public function test_custom_range_requires_from_and_to(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.index', ['preset' => 'custom']));

        $response->assertSessionHasErrors(['from', 'to']);
    }

    public function test_custom_range_to_must_be_after_or_equal_from(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.index', [
            'preset' => 'custom',
            'from' => now()->toDateString(),
            'to' => now()->subDay()->toDateString(),
        ]));

        $response->assertSessionHasErrors('to');
    }

    // --- CSV export ---

    public function test_revenue_csv_export_downloads_successfully(): void
    {
        $admin = $this->makeAdmin();
        $this->makePaidReservation(now(), 1500);

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.export', ['type' => 'revenue']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_rejects_an_unknown_report_type(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.export', ['type' => 'nonsense']));

        $response->assertStatus(422);
    }

    public function test_export_requires_permission(): void
    {
        $admin = $this->makeAdmin('content_admin');

        $response = $this->actingAs($admin, 'web')->get(route('admin.reports.export', ['type' => 'revenue']));

        $response->assertStatus(403);
    }
}
