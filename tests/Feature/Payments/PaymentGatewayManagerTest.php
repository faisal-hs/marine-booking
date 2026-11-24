<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\MockPaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\TabbyPaymentGateway;
use App\Services\Payments\TamaraPaymentGateway;
use App\Services\Payments\UnavailablePaymentGateway;
use App\Support\Jwt;
use App\Support\Money\GatewayAmount;
use Tests\Concerns\BuildsOnlinePayments;
use Tests\TestCase;

/**
 * Which gateway a payment goes through, and when a gateway is ready; and the two small helpers the gateways stand on (the signed token Tamara sends, and how an amount is written).
 */
class PaymentGatewayManagerTest extends TestCase
{
    use BuildsOnlinePayments;

    private function manager(): PaymentGatewayManager
    {
        return app(PaymentGatewayManager::class);
    }

    public function test_tabby_and_tamara_have_a_gateway_each_and_every_other_method_goes_to_the_default(): void
    {
        $this->enableTabby();
        $this->enableTamara();

        $this->assertSame(['tabby', 'tamara', 'mock', 'mock', 'mock', 'mock'], array_map(fn ($m) => $this->manager()->gatewayNameForMethod($m), ['tabby', 'tamara', 'card', 'apple_pay', 'mada', null]));
        $this->assertInstanceOf(TabbyPaymentGateway::class, $this->manager()->resolve('tabby'));
        $this->assertInstanceOf(TamaraPaymentGateway::class, $this->manager()->resolve('tamara'));
    }

    public function test_the_choice_does_not_depend_on_the_default_gateway(): void
    {
        $this->enableTabby();
        config(['payments.default' => 'nonsense']);

        $this->assertSame('tabby', $this->manager()->gatewayNameForMethod('tabby'));
        $this->assertInstanceOf(UnavailablePaymentGateway::class, $this->manager()->resolve('nonsense'), 'An unknown default is refused, not turned into the mock');
    }

    public function test_where_a_gateway_is_not_switched_on_and_nothing_is_real_the_method_falls_back_to_the_mock_outside_production_only(): void
    {
        config(['payments.default' => 'mock']);

        $this->assertSame('mock', $this->manager()->gatewayNameForMethod('tabby'));
        $this->assertInstanceOf(MockPaymentGateway::class, $this->manager()->resolve('mock'));

        $this->app['env'] = 'production';
        $this->assertSame('tabby', $this->manager()->gatewayNameForMethod('tabby'), 'In production it does not become the mock');
        $this->assertInstanceOf(UnavailablePaymentGateway::class, $this->manager()->resolve('tabby'));
        $this->assertInstanceOf(UnavailablePaymentGateway::class, $this->manager()->resolve('mock'), 'And the mock is refused');
    }

    public function test_a_gateway_that_is_switched_on_is_used_even_when_the_default_is_the_mock(): void
    {
        $this->enableTamara();
        config(['payments.default' => 'mock']);

        $this->assertSame('tamara', $this->manager()->gatewayNameForMethod('tamara'));
    }

    public function test_a_gateway_that_is_switched_on_but_missing_a_setting_says_which_and_is_refused(): void
    {
        $this->enableTabby(['webhook_secret' => null, 'merchant_code' => '']);
        $this->enableTamara(['api_token' => null]);

        $this->assertSame(['TABBY_MERCHANT_CODE', 'TABBY_WEBHOOK_SECRET'], array_values($this->manager()->missingSettings('tabby')));
        $this->assertSame(['TAMARA_API_TOKEN'], array_values($this->manager()->missingSettings('tamara')));
        $this->assertInstanceOf(UnavailablePaymentGateway::class, $this->manager()->resolve('tabby'));
        $this->assertInstanceOf(UnavailablePaymentGateway::class, $this->manager()->resolve('tamara'));
    }

    public function test_a_fully_configured_gateway_has_nothing_missing(): void
    {
        $this->enableTabby();
        $this->enableTamara();

        $this->assertSame([], $this->manager()->missingSettings('tabby'));
        $this->assertSame([], $this->manager()->missingSettings('tamara'));
    }

    public function test_only_the_default_gateway_and_switched_on_online_ones_take_webhooks(): void
    {
        config(['payments.default' => 'mock']);
        $this->assertSame([true, false, false, false], array_map(fn ($g) => $this->manager()->acceptsWebhooksFor($g), ['mock', 'tabby', 'tamara', 'stripe']));

        $this->enableTabby();
        $this->assertTrue($this->manager()->acceptsWebhooksFor('tabby'));
    }

    // --- The signed token Tamara sends ---------------------------------------------------------------------------------------------

    public function test_a_token_with_the_right_secret_is_accepted_and_its_claims_are_returned(): void
    {
        $token = Jwt::signHs256(['iss' => 'Tamara', 'exp' => time() + 600], 'secret');

        $this->assertSame('Tamara', Jwt::verifyHs256($token, 'secret')['iss']);
    }

    public function test_a_token_is_refused_when_it_is_wrong_expired_tampered_unsigned_or_the_secret_is_empty(): void
    {
        $good = Jwt::signHs256(['iss' => 'Tamara', 'exp' => time() + 600], 'secret');
        [$h, $p, $s] = explode('.', $good);
        $forged = $h.'.'.rtrim(strtr(base64_encode('{"iss":"Tamara","exp":'.(time() + 9999).'}'), '+/', '-_'), '=').'.'.$s;
        $none = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=').'.'.$p.'.';

        $this->assertNull(Jwt::verifyHs256($good, 'other'));
        $this->assertNull(Jwt::verifyHs256(Jwt::signHs256(['exp' => time() - 7200], 'secret'), 'secret'), 'Expired');
        $this->assertNotNull(Jwt::verifyHs256(Jwt::signHs256(['exp' => time() - 30], 'secret'), 'secret'), 'A few seconds of clock difference is allowed');
        $this->assertNull(Jwt::verifyHs256($forged, 'secret'), 'A changed payload');
        $this->assertNull(Jwt::verifyHs256($none, 'secret'), 'alg none');
        $this->assertNull(Jwt::verifyHs256($good, ''), 'No secret is no verification');
        $this->assertNull(Jwt::verifyHs256('only.two', 'secret'));
        $this->assertNull(Jwt::verifyHs256('', 'secret'));
    }

    // --- How an amount is written ------------------------------------------------------------------------------------------------

    public function test_amounts_are_written_with_two_decimals_and_three_for_the_currencies_that_have_fils(): void
    {
        $this->assertSame(['3000.00', '12.50', '10.500', '7.000'], [GatewayAmount::format(3000, 'SAR'), GatewayAmount::format('12.5', 'aed'), GatewayAmount::format(10.5, 'KWD'), GatewayAmount::format(7, 'BHD')]);
        $this->assertTrue(GatewayAmount::same('3000.00', 3000, 'SAR'));
        $this->assertFalse(GatewayAmount::same('3000.01', 3000, 'SAR'));
        $this->assertFalse(GatewayAmount::same(null, 3000, 'SAR'));
    }
}
