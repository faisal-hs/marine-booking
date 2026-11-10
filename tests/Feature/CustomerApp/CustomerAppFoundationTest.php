<?php

namespace Tests\Feature\CustomerApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** What every customer app endpoint has in common: the specification's response shape, who may call it, the language, and the list of routes. */
class CustomerAppFoundationTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_exactly_the_specifications_34_operations_exist_under_customer_app_and_all_carry_the_envelope(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/customer-app/'));

        $this->assertCount(34, $routes);
        foreach ($routes as $route) {
            $this->assertSame('customer-app', $route->getAction('envelope'), $route->uri());
        }
    }

    public function test_the_routes_that_need_a_customer_refuse_nobody_and_a_provider_in_the_specifications_shape(): void
    {
        $provider = $this->approvedProvider();
        $needsCustomer = [['GET', '/favorites'], ['POST', '/favorites/1'], ['DELETE', '/favorites/1'], ['POST', '/activities/1/reviews'], ['POST', '/conversations'], ['GET', '/conversations'],
            ['GET', '/conversations/1/messages'], ['POST', '/conversations/1/messages'], ['POST', '/bookings/summary'], ['POST', '/bookings'], ['GET', '/bookings'], ['GET', '/bookings/1'],
            ['POST', '/bookings/1/cancel'], ['GET', '/notifications'], ['POST', '/notifications/read-all'], ['POST', '/notifications/x/read'], ['GET', '/profile'], ['POST', '/profile/update'],
            ['DELETE', '/profile'], ['POST', '/auth/logout'], ['POST', '/support/tickets']];

        $this->assertCount(21, $needsCustomer, 'Every route that is not open to everyone');

        foreach ($needsCustomer as [$method, $path]) {
            $nobody = $this->ca(null, $method, $path)->assertUnauthorized();
            $this->assertSame(['status', 'code', 'message', 'errors'], array_keys($nobody->json()), "{$method} {$path}");
            $this->assertSame('UNAUTHENTICATED', $nobody->json('code'));

            $this->ca($provider['token'], $method, $path)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        }
    }

    public function test_the_open_routes_answer_without_a_token(): void
    {
        $this->approvedProvider();

        foreach (['/home', '/activities', '/search/popular', '/filters', '/settings', '/faqs', '/about', '/pages/terms', '/pages/privacy', '/pages/cancellation-policy'] as $path) {
            $r = $this->ca(null, 'GET', $path)->assertOk();
            $this->assertTrue($r->json('status'), $path);
            $this->assertSame('Success', $r->json('message'), $path);
            $this->assertArrayHasKey('data', $r->json(), $path);
        }
    }

    public function test_a_customers_token_that_is_not_valid_is_simply_a_guest_on_open_routes(): void
    {
        $this->approvedProvider();

        $this->ca('not-a-real-token', 'GET', '/home')->assertOk()->assertJsonPath('data.unread_notifications', 0);
    }

    public function test_a_provider_token_is_a_guest_on_open_routes_not_an_error(): void
    {
        $provider = $this->approvedProvider();

        $this->ca($provider['token'], 'GET', '/home')->assertOk()->assertJsonPath('data.unread_notifications', 0);
    }

    public function test_the_language_follows_accept_language(): void
    {
        $this->approvedProvider();

        $this->assertSame(['Diving', 'Yacht', 'Jet Ski', 'Fishing'], $this->ca(null, 'GET', '/search/popular')->json('data'));
        $this->assertSame(['غوص', 'يخوت', 'جت سكي', 'صيد'], $this->ca(null, 'GET', '/search/popular', [], ['Accept-Language' => 'ar'])->json('data'));
        $this->assertSame(['Diving', 'Yacht', 'Jet Ski', 'Fishing'], $this->ca(null, 'GET', '/search/popular', [], ['Accept-Language' => 'fr-FR'])->json('data'), 'Anything but Arabic is English');
        $this->assertSame('شروط الخدمة', $this->ca(null, 'GET', '/pages/terms', [], ['Accept-Language' => 'ar-SA,ar;q=0.9'])->json('data.title'));
    }

    public function test_a_page_has_a_meta_block_and_a_list_without_one_has_none(): void
    {
        $this->approvedProvider();

        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($this->ca(null, 'GET', '/activities')->json('meta')));
        $this->assertArrayNotHasKey('meta', $this->ca(null, 'GET', '/home')->json());
    }

    public function test_an_unknown_path_and_a_bad_page_are_answered_in_the_same_shape(): void
    {
        $this->approvedProvider();

        $this->ca(null, 'GET', '/activities', ['page' => 0])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['status', 'code', 'message', 'errors' => ['page']]);
        $this->ca(null, 'GET', '/activities/99999')->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND')->assertJsonPath('status', false);
        $this->ca(null, 'GET', '/activities/abc')->assertNotFound();
    }
}
