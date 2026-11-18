<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /auth/customer/otp/request takes an optional `city` when a customer registers: it is kept with the pending registration and saved on the customer when the code is
 * verified and the account is made. It is free text up to 120 characters, as a provider's city is.
 */
class CustomerRegistrationCityTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST = '/api/v1/auth/customer/otp/request';

    private const VERIFY = '/api/v1/auth/customer/otp/verify';

    private function register(array $extra = [], string $phone = '0551234567'): array
    {
        $this->postJson(self::REQUEST, array_merge(['phone' => $phone, 'name' => 'Layla Hassan'], $extra))->assertSuccessful();
        $verified = $this->postJson(self::VERIFY, ['phone' => $phone, 'code' => config('otp.default_code')])->assertSuccessful();

        return ['token' => $verified->json('data.token'), 'response' => $verified];
    }

    private function cityOf(string $phone = '0551234567'): ?string
    {
        return Customer::whereHas('user', fn ($q) => $q->where('phone', $phone))->firstOrFail()->city;
    }

    private function profile(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->getJson('/api/v1/customer-app/profile')->assertOk();
    }

    // --- Registering with a city ------------------------------------------------------------------------------------

    public function test_a_customer_who_registers_with_a_city_has_it_saved_when_the_account_is_made(): void
    {
        $this->register(['city' => 'Jeddah']);

        $this->assertSame('Jeddah', $this->cityOf());
    }

    public function test_the_city_is_in_the_sign_in_answer_and_in_the_profile(): void
    {
        $registered = $this->register(['city' => 'Jeddah']);

        $customer = collect($registered['response']->json('data'))->first(fn ($value) => is_array($value) && array_key_exists('city', $value));
        $this->assertNotNull($customer, 'The customer part of the sign-in answer has a city');
        $this->assertSame('Jeddah', $customer['city']);
        $this->assertSame('Jeddah', $this->profile($registered['token'])->json('data.city'));
    }

    public function test_it_may_be_left_out_and_the_customer_then_has_none(): void
    {
        $registered = $this->register();

        $this->assertNull($this->cityOf());
        $this->assertNull($this->profile($registered['token'])->json('data.city'));
    }

    public function test_a_city_in_arabic_is_kept_as_it_was_written(): void
    {
        $registered = $this->register(['city' => 'جدة']);

        $this->assertSame('جدة', $this->cityOf());
        $this->assertSame('جدة', $this->profile($registered['token'])->json('data.city'));
    }

    public function test_spaces_around_it_are_dropped_and_an_empty_one_is_no_city(): void
    {
        $this->register(['city' => '   Dammam  '], '0551234501');
        $this->register(['city' => ''], '0551234502');
        $this->register(['city' => '   '], '0551234503');

        $this->assertSame('Dammam', $this->cityOf('0551234501'));
        $this->assertNull($this->cityOf('0551234502'));
        $this->assertNull($this->cityOf('0551234503'));
    }

    public function test_it_is_kept_if_the_code_is_sent_again(): void
    {
        config(['otp.resend_cooldown_seconds' => 0]);   // a code may be sent again after a minute: not waited for here
        $this->postJson(self::REQUEST, ['phone' => '0551234567', 'name' => 'Layla Hassan', 'city' => 'Yanbu'])->assertSuccessful();
        $this->postJson('/api/v1/auth/customer/otp/resend', ['phone' => '0551234567'])->assertSuccessful();
        $this->postJson(self::VERIFY, ['phone' => '0551234567', 'code' => config('otp.default_code')])->assertSuccessful();

        $this->assertSame('Yanbu', $this->cityOf());
    }

    // --- What is refused ---------------------------------------------------------------------------------------------

    public function test_a_city_longer_than_a_hundred_and_twenty_characters_is_refused_and_nothing_is_started(): void
    {
        $this->postJson(self::REQUEST, ['phone' => '0551234567', 'name' => 'Layla', 'city' => str_repeat('a', 121)])->assertStatus(422)->assertJsonValidationErrors('city');
        $this->postJson(self::REQUEST, ['phone' => '0551234567', 'name' => 'Layla', 'city' => ['Jeddah']])->assertStatus(422)->assertJsonValidationErrors('city');

        $this->assertSame(0, DB::table('customers')->count());
        $this->postJson(self::REQUEST, ['phone' => '0551234567', 'name' => 'Layla', 'city' => str_repeat('a', 120)])->assertSuccessful();
    }

    public function test_a_city_alone_does_not_register_anyone_the_name_is_still_required(): void
    {
        $this->postJson(self::REQUEST, ['phone' => '0551234567', 'city' => 'Jeddah'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame(0, DB::table('users')->count());
    }

    // --- A customer who already has an account -----------------------------------------------------------------------

    public function test_signing_in_again_with_a_city_does_not_change_the_one_on_the_account(): void
    {
        $this->register(['city' => 'Riyadh']);

        $this->postJson(self::REQUEST, ['phone' => '0551234567', 'city' => 'Dammam'])->assertSuccessful();
        $this->postJson(self::VERIFY, ['phone' => '0551234567', 'code' => config('otp.default_code')])->assertSuccessful();

        $this->assertSame('Riyadh', $this->cityOf(), 'Signing in is not changing the profile');
    }

    // --- Changing it later -------------------------------------------------------------------------------------------

    public function test_the_customer_can_change_it_in_the_profile_and_take_it_off(): void
    {
        $token = $this->register(['city' => 'Jeddah'])['token'];
        $update = function (array $body) use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->postJson('/api/v1/customer-app/profile/update', $body);
        };

        $this->assertSame('Dammam', $update(['city' => 'Dammam'])->assertOk()->json('data.city'));
        $this->assertSame('Dammam', $this->cityOf());

        $this->assertNull($update(['city' => null])->assertOk()->json('data.city'));
        $this->assertNull($this->cityOf());

        $update(['city' => str_repeat('a', 121)])->assertStatus(422);
        $this->assertSame('Dammam', $update(['city' => 'Dammam'])->json('data.city'));
        $this->assertSame('Dammam', $update(['name' => 'Layla H.'])->assertOk()->json('data.city'), 'A change of name leaves the city alone');
    }

    public function test_the_account_answer_for_a_customer_carries_the_city(): void
    {
        $token = $this->register(['city' => 'Jeddah'])['token'];
        $this->app['auth']->forgetGuards();

        $account = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->getJson('/api/v1/account')->assertOk()->json('data');
        $profile = collect($account)->first(fn ($value) => is_array($value) && array_key_exists('city', $value));

        $this->assertNotNull($profile);
        $this->assertSame('Jeddah', $profile['city']);
    }
}
