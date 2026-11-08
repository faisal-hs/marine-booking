<?php

namespace Tests\Feature\ProviderApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The provider app API answers in the spec's shape, and only the provider app does: { status, message, data, meta } and
 * { status, code, message, errors }, with page and per_page (max 100). Everything else keeps its own envelope.
 */
class ProviderAppEnvelopeTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_success_has_status_message_and_data_and_nothing_of_the_older_envelope(): void
    {
        $a = $this->approvedProvider();

        $response = $this->hit($a['token'], 'GET', '/api/v1/provider/categories')->assertOk();

        $this->assertSame(['status', 'message', 'data'], array_keys($response->json()));
        $this->assertTrue($response->json('status'));
        $this->assertSame('Success', $response->json('message'));
    }

    public function test_a_page_has_meta_in_the_specs_names_and_the_default_page_size_is_20(): void
    {
        $a = $this->approvedProvider();

        $response = $this->hit($a['token'], 'GET', '/api/v1/provider/bookings')->assertOk();

        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0], $response->json('meta'));
        $this->assertArrayNotHasKey('pagination', $response->json());
        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_per_page_is_capped_at_100_and_page_selects_the_page(): void
    {
        $a = $this->approvedProvider();
        $customer = $this->customerNamed('Fatima');
        foreach (range(1, 5) as $day) {
            $this->makeBooking($a, $customer, '2026-11-'.str_pad((string) (15 + $day), 2, '0', STR_PAD_LEFT));
        }

        $this->hit($a['token'], 'GET', '/api/v1/provider/bookings?per_page=101')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('per_page');
        $this->hit($a['token'], 'GET', '/api/v1/provider/bookings?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);

        $second = $this->hit($a['token'], 'GET', '/api/v1/provider/bookings?per_page=2&page=2')->assertOk();
        $this->assertSame(2, $second->json('meta.current_page'));
        $this->assertSame(3, $second->json('meta.last_page'));
        $this->assertSame(5, $second->json('meta.total'));
        $this->assertCount(2, $second->json('data.bookings'));
    }

    public function test_a_validation_failure_has_the_specs_error_shape(): void
    {
        $a = $this->approvedProvider();

        $response = $this->hit($a['token'], 'GET', '/api/v1/provider/bookings?filter=nonsense')->assertStatus(422);

        $this->assertSame(['status', 'code', 'message', 'errors'], array_keys($response->json()));
        $this->assertFalse($response->json('status'));
        $this->assertSame('VALIDATION_ERROR', $response->json('code'));
        $this->assertArrayHasKey('filter', $response->json('errors'));
    }

    public function test_without_a_token_it_is_401_unauthenticated_and_errors_is_an_object_even_when_empty(): void
    {
        foreach (['/api/v1/provider/categories', '/api/v1/provider/bookings', '/api/v1/provider/apply'] as $uri) {
            $response = $this->hit(null, 'GET', $uri)->assertUnauthorized();

            $this->assertSame('UNAUTHENTICATED', $response->json('code'), $uri);
            $this->assertFalse($response->json('status'));
            $this->assertStringContainsString('"errors":{}', $response->getContent(), 'errors must be an object, not an empty list');
        }
    }

    public function test_a_customer_token_is_403_forbidden(): void
    {
        [$customer] = $this->makeCustomer();

        foreach (['/api/v1/provider/categories', '/api/v1/provider/bookings'] as $uri) {
            $this->hit($this->tokenFor($customer, 'customer'), 'GET', $uri)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN')->assertJsonPath('status', false);
        }
    }

    public function test_an_unapproved_provider_is_turned_away_from_the_operations_with_provider_not_approved(): void
    {
        $p = $this->unapprovedProvider('pending');

        foreach (['/api/v1/provider/dashboard', '/api/v1/provider/bookings', '/api/v1/provider/calendar?month=2026-11', '/api/v1/provider/customers'] as $uri) {
            $response = $this->hit($p['token'], 'GET', $uri)->assertForbidden();

            $this->assertSame('PROVIDER_NOT_APPROVED', $response->json('code'), $uri);
            $this->assertFalse($response->json('status'));
            $this->assertArrayHasKey('provider_request_status', $response->json('data'));
            $this->assertStringContainsString('"errors":{}', $response->getContent());
        }
    }

    public function test_the_rest_of_the_api_keeps_its_own_envelope(): void
    {
        $a = $this->approvedProvider();

        // An older provider route, same prefix: still { success, ... } both when it works and when it fails.
        $this->hit($a['token'], 'GET', '/api/v1/provider/profile')->assertOk()->assertJsonPath('success', true)->assertJsonMissingPath('status');
        $this->hit(null, 'GET', '/api/v1/provider/profile')->assertUnauthorized()->assertJsonPath('success', false)->assertJsonMissingPath('status');
        $this->hit(null, 'GET', '/api/v1/customer/reservations')->assertUnauthorized()->assertJsonPath('success', false);
    }

    public function test_exactly_the_routes_of_the_provider_app_file_carry_the_envelope_attribute(): void
    {
        $withIt = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getAction('envelope') === 'provider-app') {
                $withIt[] = array_values(array_diff($route->methods(), ['HEAD']))[0].' '.$route->uri();
            }
        }
        sort($withIt);

        $expected = [
            'GET api/v1/provider/apply', 'GET api/v1/provider/bookings', 'GET api/v1/provider/calendar', 'GET api/v1/provider/categories', 'GET api/v1/provider/customers',
            'GET api/v1/provider/dashboard', 'PATCH api/v1/provider/activities/{id}/status', 'PATCH api/v1/provider/profile', 'POST api/v1/provider/apply', 'POST api/v1/provider/apply/resubmit',
            'POST api/v1/provider/bookings/{id}/cancel', 'GET api/v1/provider/earnings', 'GET api/v1/provider/earnings/report', 'GET api/v1/provider/earnings/statement',
            'GET api/v1/provider/earnings/statement/download', 'GET api/v1/provider/bank-account', 'PUT api/v1/provider/bank-account',
            'POST api/v1/provider/withdrawals', 'GET api/v1/provider/verification', 'POST api/v1/provider/verification/{key}', 'GET api/v1/provider/verification/files/{document}', 'GET api/v1/provider/withdrawals', 'GET api/v1/provider/withdrawals/{id}',
        ];
        sort($expected);

        $this->assertSame($expected, $withIt, 'A route with the attribute answers in the spec shape; a route without it must not');
    }
}
