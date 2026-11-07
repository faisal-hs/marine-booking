<?php

namespace Tests\Feature;

use App\Models\ActivityPricingRule;
use App\Services\ActivityPricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The platform currency is Saudi Riyal (config/platform.php). Every price, booking and payment records the currency it was made in, and
 * changing the setting later affects only NEW records: nothing already stored is converted or relabelled.
 */
class PlatformCurrencyTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    public function test_the_platform_currency_is_saudi_riyal_and_is_documented(): void
    {
        $this->assertSame('SAR', config('platform.currency'));
        $this->assertStringContainsString('PLATFORM_CURRENCY=SAR', file_get_contents(base_path('.env.example')));
    }

    public function test_a_price_made_without_a_currency_is_in_the_platform_currency(): void
    {
        $a = $this->approvedProvider();

        $rule = ActivityPricingRule::create(['activity_booking_option_id' => $a['option']->id, 'participant_type' => 'child', 'price' => 700]);

        $this->assertSame('SAR', $rule->refresh()->currency);
        $this->assertSame('SAR', DB::table('activity_pricing_rules')->where('id', $a['option']->pricingRules()->first()->id)->value('currency'), 'The helper made its rule without one too');
    }

    public function test_a_price_that_names_its_own_currency_keeps_it_and_changing_the_setting_never_converts_what_exists(): void
    {
        $a = $this->approvedProvider();
        $old = ActivityPricingRule::create(['activity_booking_option_id' => $a['option']->id, 'participant_type' => 'child', 'price' => 700, 'currency' => 'EGP']);
        $before = $a['option']->pricingRules()->where('participant_type', 'adult')->first();

        config(['platform.currency' => 'USD']);
        $new = ActivityPricingRule::create(['activity_booking_option_id' => $a['option']->id, 'participant_type' => 'infant', 'price' => 100]);

        $this->assertSame('USD', $new->refresh()->currency, 'New records follow the setting');
        $this->assertSame('EGP', $old->refresh()->currency, 'A record that named its currency keeps it');
        $this->assertSame('SAR', $before->refresh()->currency, 'A record made before the change keeps what it was made in');
        $this->assertEquals(700, $old->price, 'And its amount is not touched either');
    }

    public function test_a_booking_and_its_payment_carry_the_currency_of_the_price(): void
    {
        $a = $this->approvedProvider();
        [, $customer] = $this->makeCustomer();

        $booking = $this->bookActivity($customer, $a['activity'], $a['option']);   // the helper picks the next date that has a departure
        $this->payFor($booking);

        $this->assertSame('SAR', $booking->refresh()->currency);
        $this->assertSame('SAR', $booking->payments()->first()->currency);
    }

    public function test_a_quote_with_no_currency_of_its_own_falls_back_to_the_platform_currency(): void
    {
        $a = $this->approvedProvider();
        $service = app(ActivityPricingService::class);
        $build = new \ReflectionMethod($service, 'buildResult');   // the defensive branch: a rule normally always has a currency

        $this->assertSame('SAR', $build->invoke($service, $a['option'], null, 100.0, [])['currency']);
        $this->assertSame('EGP', $build->invoke($service, $a['option'], 'EGP', 100.0, [])['currency'], 'A currency that is given is kept');

        config(['platform.currency' => 'USD']);
        $this->assertSame('USD', $build->invoke($service, $a['option'], null, 100.0, [])['currency']);
    }

    public function test_the_admin_screens_show_the_platform_currency_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            foreach (['admin.reports.revenue', 'admin.providers.gross_revenue', 'admin.customers.total_paid'] as $key) {
                $label = __($key, ['currency' => config('platform.currency')]);

                $this->assertStringContainsString('SAR', $label, "{$key} in {$locale}");
                $this->assertStringNotContainsString(':currency', $label);
                $this->assertStringNotContainsString('EGP', $label);
            }
        }

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->flushHeaders();
        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.dashboard'))->assertOk()->assertSee('(SAR)', false)->assertDontSee('(EGP)', false);
    }
}
