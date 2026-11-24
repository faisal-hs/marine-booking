<?php

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\TestCase;

/** `payments:check` (settings, and with --live the credentials) and `payments:tabby:register-webhook`. */
class PaymentCommandsTest extends TestCase
{
    use BuildsOnlinePayments;
    use RefreshDatabase;

    public function test_nothing_switched_on_is_not_a_problem(): void
    {
        $this->artisan('payments:check')->expectsOutputToContain('TABBY: not enabled')->expectsOutputToContain('TAMARA: not enabled')->assertSuccessful();
    }

    public function test_a_gateway_that_is_switched_on_and_missing_a_setting_fails_and_names_it(): void
    {
        $this->enableTabby(['secret_key' => '']);
        $this->enableTamara();

        $this->artisan('payments:check')->expectsOutputToContain('missing: TABBY_SECRET_KEY')->assertFailed();
    }

    public function test_a_fully_set_up_gateway_passes_and_shows_where_it_will_be_called(): void
    {
        $this->enableTabby();
        $this->enableTamara();

        $this->artisan('payments:check')->expectsOutputToContain('/api/v1/webhooks/payments/tabby')->expectsOutputToContain('/api/v1/webhooks/payments/tamara')->assertSuccessful();
    }

    public function test_live_makes_one_read_per_gateway_with_its_own_credentials_and_creates_nothing(): void
    {
        $this->enableTabby();
        $this->enableTamara();
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('payments:check --live')->expectsOutputToContain('the credentials work')->assertSuccessful();

        $requests = Http::recorded()->map(fn ($pair) => $pair[0])->all();
        $this->assertCount(2, $requests);
        $this->assertSame(['GET', 'GET'], array_map(fn ($r) => $r->method(), $requests));
        $this->assertStringContainsString('/api/v2/payments', $requests[0]->url());
        $this->assertSame('Bearer sk_test_secret', $requests[0]->header('Authorization')[0]);
        $this->assertStringContainsString('/checkout/payment-types', $requests[1]->url());
        $this->assertSame('Bearer tamara-api-token', $requests[1]->header('Authorization')[0]);
    }

    public function test_live_fails_and_says_so_when_a_gateway_refuses_the_credentials(): void
    {
        $this->enableTabby();
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->artisan('payments:check --live')->expectsOutputToContain('the credentials were refused')->assertFailed();
    }

    public function test_registering_the_tabby_webhook_sends_the_address_the_header_and_the_merchant_code(): void
    {
        $this->enableTabby();
        Http::fake(['*' => Http::response(['id' => 'wh_1'], 200)]);

        $this->artisan('payments:tabby:register-webhook')->expectsOutputToContain('Registered')->assertSuccessful();

        $request = Http::recorded()[0][0];
        $this->assertStringEndsWith('/api/v1/webhooks', $request->url());
        $this->assertSame('SA', $request->header('X-Merchant-Code')[0]);
        $this->assertSame('Bearer sk_test_secret', $request->header('Authorization')[0]);
        $this->assertSame([route('webhooks.payments', ['gateway' => 'tabby']), true, 'X-Tabby-Webhook-Secret', 'tabby-hook-secret'], [$request['url'], $request['is_test'], $request['header']['title'], $request['header']['value']]);
    }

    public function test_it_registers_for_live_payments_when_tabby_is_in_production(): void
    {
        $this->enableTabby(['environment' => 'production']);
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('payments:tabby:register-webhook')->expectsOutputToContain('live payments')->assertSuccessful();

        $this->assertFalse(Http::recorded()[0][0]['is_test']);
    }

    public function test_registering_without_the_settings_or_when_tabby_refuses_fails(): void
    {
        $this->enableTabby(['webhook_secret' => '']);
        $this->artisan('payments:tabby:register-webhook')->expectsOutputToContain('TABBY_WEBHOOK_SECRET is not set')->assertFailed();

        $this->enableTabby();
        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);
        $this->artisan('payments:tabby:register-webhook')->expectsOutputToContain('Tabby refused it')->assertFailed();
    }

    public function test_a_complete_installation_is_reported_as_complete(): void
    {
        $this->artisan('payments:check')->expectsOutputToContain('Installation: complete')->assertSuccessful();
    }

    public function test_a_missing_table_or_column_is_reported_and_fails_the_check(): void
    {
        Schema::drop('payment_events');

        $this->artisan('payments:check')->expectsOutputToContain('the payment_events table does not exist: run php artisan migrate.')->assertFailed();
    }

    public function test_the_columns_are_checked_too(): void
    {
        Schema::table('payments', fn ($table) => $table->dropIndex('payments_gateway_status_index'));
        Schema::table('payments', fn ($table) => $table->dropColumn('checkout_url'));

        $this->artisan('payments:check')->expectsOutputToContain('the payments table has no `checkout_url` column: run php artisan migrate.')->assertFailed();
    }
}
