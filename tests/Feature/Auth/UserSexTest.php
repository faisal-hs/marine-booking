<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The SEX of the person behind an account: `male` or `female`, or nothing (nobody has to say), on the user, so a customer and a provider have it in one place. It is asked for when
 * registering and in the profile, and shown wherever the profile is. The EMAIL is optional for both: an account may have none, and may take it off.
 */
class UserSexTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const CUSTOMER_REQUEST = '/api/v1/auth/customer/otp/request';

    private const CUSTOMER_VERIFY = '/api/v1/auth/customer/otp/verify';

    private const PROVIDER_REQUEST = '/api/v1/auth/provider/otp/request';

    private const PROVIDER_VERIFY = '/api/v1/auth/provider/otp/verify';

    private function registerCustomer(array $extra = [], string $phone = '0551234567'): array
    {
        $this->postJson(self::CUSTOMER_REQUEST, array_merge(['phone' => $phone, 'name' => 'Layla Hassan'], $extra))->assertSuccessful();
        $verified = $this->postJson(self::CUSTOMER_VERIFY, ['phone' => $phone, 'code' => config('otp.default_code')])->assertSuccessful();

        return ['token' => $verified->json('data.token'), 'response' => $verified, 'user' => User::where('phone', $phone)->firstOrFail()];
    }

    private function registerProvider(array $extra = [], string $phone = '0561234567'): array
    {
        $this->postJson(self::PROVIDER_REQUEST, array_merge(['phone' => $phone, 'owner_name' => 'Omar Said', 'business_name' => 'Blue Hole Dive Club'], $extra))->assertSuccessful();
        $verified = $this->postJson(self::PROVIDER_VERIFY, ['phone' => $phone, 'code' => config('otp.default_code')])->assertSuccessful();

        return ['token' => $verified->json('data.token'), 'response' => $verified, 'user' => User::where('phone', $phone)->firstOrFail()];
    }

    private function as(string $token, string $method, string $uri, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->json($method, $uri, $body);
    }

    private function signInUser(array $response): array
    {
        return collect($response)->first(fn ($v) => is_array($v) && array_key_exists('sex', $v)) ?? [];
    }

    // --- Registering ------------------------------------------------------------------------------------------------

    public function test_a_customer_who_registers_with_a_sex_has_it_on_the_account_and_in_the_sign_in_answer(): void
    {
        foreach (['male' => '0551234501', 'female' => '0551234502'] as $sex => $phone) {
            $registered = $this->registerCustomer(['sex' => $sex], $phone);

            $this->assertSame($sex, $registered['user']->sex);
            $this->assertSame($sex, $this->signInUser($registered['response']->json('data'))['sex'] ?? null, 'The sign-in answer has it');
            $this->assertSame($sex, $this->as($registered['token'], 'GET', '/api/v1/customer-app/profile')->assertOk()->json('data.sex'));
        }
    }

    public function test_a_provider_who_registers_with_a_sex_has_it_on_the_account_and_on_the_profile(): void
    {
        $registered = $this->registerProvider(['sex' => 'female']);

        $this->assertSame('female', $registered['user']->sex);
        $this->assertSame('female', $this->signInUser($registered['response']->json('data'))['sex'] ?? null);
    }

    public function test_the_sex_is_optional_and_then_the_account_has_none(): void
    {
        $customer = $this->registerCustomer();
        $provider = $this->registerProvider();

        $this->assertNull($customer['user']->sex);
        $this->assertNull($provider['user']->sex);
        $this->assertNull($this->as($customer['token'], 'GET', '/api/v1/customer-app/profile')->json('data.sex'));
        $this->assertArrayHasKey('sex', $this->as($customer['token'], 'GET', '/api/v1/customer-app/profile')->json('data'), 'It is in the answer, as null');
    }

    public function test_a_value_that_is_not_male_or_female_is_refused_by_name_and_nothing_is_started(): void
    {
        // (A different phone each time: a code may be asked for only a few times a minute for one number.)
        foreach (['other', 'Male', 'FEMALE', 'm', '1', 'yes'] as $i => $bad) {
            $this->postJson(self::CUSTOMER_REQUEST, ['phone' => '05512346'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'name' => 'Layla', 'sex' => $bad])->assertStatus(422)->assertJsonValidationErrors('sex');
            $this->postJson(self::PROVIDER_REQUEST, ['phone' => '05612346'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'owner_name' => 'Omar', 'business_name' => 'Club', 'sex' => $bad])->assertStatus(422)->assertJsonValidationErrors('sex');
        }
        $this->postJson(self::CUSTOMER_REQUEST, ['phone' => '0551234567', 'name' => 'Layla', 'sex' => ['male']])->assertStatus(422)->assertJsonValidationErrors('sex');

        $this->assertSame(0, User::count());
        $this->postJson(self::CUSTOMER_REQUEST, ['phone' => '0551234567', 'name' => 'Layla', 'sex' => ''])->assertSuccessful();   // an empty one is "not said"
    }

    public function test_signing_in_again_with_a_sex_does_not_change_the_one_on_the_account(): void
    {
        $this->registerCustomer(['sex' => 'female']);

        $this->postJson(self::CUSTOMER_REQUEST, ['phone' => '0551234567', 'sex' => 'male'])->assertSuccessful();
        $this->postJson(self::CUSTOMER_VERIFY, ['phone' => '0551234567', 'code' => config('otp.default_code')])->assertSuccessful();

        $this->assertSame('female', User::where('phone', '0551234567')->value('sex'), 'Signing in is not changing the profile');
    }

    public function test_it_is_kept_if_the_code_is_sent_again(): void
    {
        config(['otp.resend_cooldown_seconds' => 0]);
        $this->postJson(self::CUSTOMER_REQUEST, ['phone' => '0551234567', 'name' => 'Layla', 'sex' => 'female'])->assertSuccessful();
        $this->postJson('/api/v1/auth/customer/otp/resend', ['phone' => '0551234567'])->assertSuccessful();
        $this->postJson(self::CUSTOMER_VERIFY, ['phone' => '0551234567', 'code' => config('otp.default_code')])->assertSuccessful();

        $this->assertSame('female', User::where('phone', '0551234567')->value('sex'));
    }

    // --- The customer's profile -------------------------------------------------------------------------------------

    public function test_the_customer_app_profile_sets_changes_and_takes_off_the_sex(): void
    {
        $token = $this->registerCustomer()['token'];

        $this->assertSame('female', $this->as($token, 'POST', '/api/v1/customer-app/profile/update', ['sex' => 'female'])->assertOk()->json('data.sex'));
        $this->assertSame('male', $this->as($token, 'POST', '/api/v1/customer-app/profile/update', ['sex' => 'male'])->assertOk()->json('data.sex'));
        $this->assertSame('male', $this->as($token, 'POST', '/api/v1/customer-app/profile/update', ['name' => 'Layla H.'])->assertOk()->json('data.sex'), 'A change of name leaves it');
        $this->assertNull($this->as($token, 'POST', '/api/v1/customer-app/profile/update', ['sex' => null])->assertOk()->json('data.sex'), 'null takes it off');

        $this->as($token, 'POST', '/api/v1/customer-app/profile/update', ['sex' => 'other'])->assertStatus(422)->assertJsonValidationErrors('sex');
    }

    public function test_the_older_customer_profile_sets_it_and_the_account_answer_has_it(): void
    {
        $registered = $this->registerCustomer();

        $this->as($registered['token'], 'PUT', '/api/v1/customer/profile', ['sex' => 'female'])->assertOk();
        $this->as($registered['token'], 'PUT', '/api/v1/customer/profile', ['sex' => 'robot'])->assertStatus(422)->assertJsonValidationErrors('sex');

        $this->assertSame('female', $registered['user']->fresh()->sex);
        $this->assertSame('female', $this->as($registered['token'], 'GET', '/api/v1/customer/profile')->assertOk()->json('data.sex'));
        $account = $this->as($registered['token'], 'GET', '/api/v1/account')->assertOk()->json('data');
        $this->assertSame('female', collect($account)->first(fn ($v) => is_array($v) && array_key_exists('sex', $v))['sex'] ?? null);
    }

    // --- The provider's profile -------------------------------------------------------------------------------------

    public function test_the_provider_profile_sets_changes_and_takes_off_the_sex_whichever_way_it_is_sent(): void
    {
        $p = $this->approvedProvider();

        foreach (['PATCH', 'PUT', 'POST'] as $method) {
            $this->hit($p['token'], $method, '/api/v1/provider/profile', ['sex' => 'female'])->assertOk();
            $this->assertSame('female', $this->hit($p['token'], 'GET', '/api/v1/provider/profile')->json('data.sex'), $method);
            $this->hit($p['token'], 'PATCH', '/api/v1/provider/profile', ['sex' => 'male'])->assertOk();
        }

        $this->assertNull($this->hit($p['token'], 'PATCH', '/api/v1/provider/profile', ['sex' => null])->assertOk()->json('data.sex'));
        $this->hit($p['token'], 'PATCH', '/api/v1/provider/profile', ['sex' => 'other'])->assertStatus(422)->assertJsonValidationErrors('sex');
        $this->assertNull($p['user']->fresh()->sex);
    }

    // --- Email is optional ------------------------------------------------------------------------------------------

    public function test_an_account_with_no_email_works_and_the_email_can_be_taken_off(): void
    {
        $customer = $this->registerCustomer();
        $provider = $this->approvedProvider();
        $this->assertNull($customer['user']->email, 'Registered by phone: no email');

        $this->as($customer['token'], 'POST', '/api/v1/customer-app/profile/update', ['email' => 'layla@example.com'])->assertOk();
        $this->assertSame('layla@example.com', $this->as($customer['token'], 'GET', '/api/v1/customer-app/profile')->json('data.email'));
        $this->assertNull($this->as($customer['token'], 'POST', '/api/v1/customer-app/profile/update', ['email' => null])->assertOk()->json('data.email'));

        $this->hit($provider['token'], 'PATCH', '/api/v1/provider/profile', ['email' => 'club@example.com'])->assertOk();
        $this->assertNull($this->hit($provider['token'], 'PATCH', '/api/v1/provider/profile', ['email' => null])->assertOk()->json('data.email'));
        $this->assertNull($provider['user']->fresh()->email);
        $this->hit($provider['token'], 'GET', '/api/v1/provider/profile')->assertOk();   // and everything still answers
    }

    public function test_an_email_that_is_given_must_still_be_an_email_and_a_new_one(): void
    {
        $a = $this->registerCustomer([], '0551234501');
        $b = $this->registerCustomer([], '0551234502');
        $this->as($a['token'], 'POST', '/api/v1/customer-app/profile/update', ['email' => 'same@example.com'])->assertOk();

        $this->as($b['token'], 'POST', '/api/v1/customer-app/profile/update', ['email' => 'not an email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->as($b['token'], 'POST', '/api/v1/customer-app/profile/update', ['email' => 'same@example.com'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_two_accounts_with_no_email_do_not_clash(): void
    {
        $this->registerCustomer([], '0551234501');
        $this->registerCustomer([], '0551234502');
        $this->registerProvider([], '0561234501');
        $this->registerProvider([], '0561234502');

        $this->assertSame(4, User::whereNull('email')->count(), 'Nobody has to have an email, and nobody shares "none"');
    }
}
