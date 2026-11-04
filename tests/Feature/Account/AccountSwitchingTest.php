<?php

namespace Tests\Feature\Account;

use App\Models\Otp;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * One phone can hold a customer account and a provider account. The customer account needs no
 * approval (it is created on first use); the provider account exists only once an admin approved a
 * request. A token is signed into exactly one of them, and the app can switch, choose a default, and
 * always learn which account it is in.
 */
class AccountSwitchingTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private function account(string $token)
    {
        return $this->asAccount($token)->getJson('/api/v1/account');
    }

    // --- Which accounts a person can use -----------------------------------------------

    public function test_a_customer_has_only_the_customer_account(): void
    {
        [$user] = $this->makeCustomer();

        $this->account($this->tokenFor($user, 'customer'))->assertOk()
            ->assertJsonPath('data.account.account_type', 'customer')
            ->assertJsonPath('data.account.default_account', 'customer')
            ->assertJsonPath('data.account.available_accounts', ['customer'])
            ->assertJsonPath('data.account.provider_request_status', 'none')
            ->assertJsonPath('data.user.phone', $user->phone)
            ->assertJsonPath('data.profile.phone', $user->phone);
    }

    public function test_a_person_with_an_approved_provider_account_has_both(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $this->account($this->tokenFor($user, 'provider'))->assertOk()
            ->assertJsonPath('data.account.account_type', 'provider')
            ->assertJsonPath('data.account.available_accounts', ['customer', 'provider'])
            ->assertJsonPath('data.account.provider_request_status', 'approved')
            ->assertJsonPath('data.profile.business_name', 'Dual Divers');
    }

    /** @return array<string, array{0: string, 1: string}> [provider status, request status shown] */
    public static function unapprovedStates(): array
    {
        return ['waiting' => ['pending', 'not_submitted'], 'rejected' => ['rejected', 'rejected']];
    }

    #[DataProvider('unapprovedStates')]
    public function test_a_provider_account_that_is_not_approved_is_not_offered_for_switching(string $status, string $shown): void
    {
        [$user] = $this->makeDualAccount($status);

        $this->account($this->tokenFor($user, 'customer'))->assertOk()
            ->assertJsonPath('data.account.available_accounts', ['customer'])
            ->assertJsonPath('data.account.provider_request_status', $shown);
    }

    public function test_the_account_endpoint_is_for_signed_in_people_only(): void
    {
        $this->getJson('/api/v1/account')->assertUnauthorized();
    }

    public function test_both_profile_endpoints_say_which_account_they_are(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $this->asAccount($this->tokenFor($user, 'customer'))->getJson('/api/v1/customer/profile')->assertOk()
            ->assertJsonPath('data.account.account_type', 'customer')
            ->assertJsonPath('data.account.available_accounts', ['customer', 'provider']);

        $this->asAccount($this->tokenFor($user, 'provider'))->getJson('/api/v1/provider/profile')->assertOk()
            ->assertJsonPath('data.account.account_type', 'provider')
            ->assertJsonPath('data.account.provider_request_status', 'approved');
    }

    // --- The default account ------------------------------------------------------------

    public function test_the_default_is_the_account_the_person_registered_as(): void
    {
        [$customerOrigin] = $this->makeDualAccount('verified');
        [$providerUser] = $this->makeProvider('verified');

        $this->account($this->tokenFor($customerOrigin, 'customer'))->assertJsonPath('data.account.default_account', 'customer');
        $this->account($this->tokenFor($providerUser, 'provider'))->assertJsonPath('data.account.default_account', 'provider');
    }

    public function test_a_person_can_choose_their_default_account(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $token = $this->tokenFor($user, 'customer');

        $this->asAccount($token)->putJson('/api/v1/account/default', ['default_account' => 'provider'])->assertOk()
            ->assertJsonPath('data.account.default_account', 'provider')
            // Choosing a default does not move the account the token is signed into.
            ->assertJsonPath('data.account.account_type', 'customer');

        $this->assertSame('provider', $user->fresh()->default_account);
    }

    public function test_the_default_cannot_be_an_account_the_person_cannot_use(): void
    {
        [$customer] = $this->makeCustomer();
        [$waiting] = $this->makeDualAccount('pending');

        foreach ([$customer, $waiting] as $user) {
            $this->asAccount($this->tokenFor($user, 'customer'))->putJson('/api/v1/account/default', ['default_account' => 'provider'])
                ->assertStatus(422)->assertJsonValidationErrors('default_account');
            $this->assertNull($user->fresh()->default_account);
        }
    }

    public function test_an_unknown_default_is_rejected(): void
    {
        [$user] = $this->makeCustomer();

        $this->asAccount($this->tokenFor($user, 'customer'))->putJson('/api/v1/account/default', ['default_account' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('default_account');
        $this->asAccount($this->tokenFor($user, 'customer'))->putJson('/api/v1/account/default', [])->assertStatus(422);
    }

    public function test_a_default_that_stops_being_usable_falls_back_to_the_registered_account(): void
    {
        [$user,, $provider] = $this->makeDualAccount('verified');
        $user->update(['default_account' => 'provider']);
        $provider->update(['verification_status' => 'rejected']);

        $this->account($this->tokenFor($user, 'customer'))->assertJsonPath('data.account.default_account', 'customer');
    }

    // --- Switching ----------------------------------------------------------------------

    public function test_switching_to_the_provider_account_returns_a_provider_token_and_the_provider_data(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $old = $this->tokenFor($user, 'customer');

        $response = $this->asAccount($old)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertOk();

        $response->assertJsonPath('data.account.account_type', 'provider')
            ->assertJsonPath('data.account.available_accounts', ['customer', 'provider'])
            ->assertJsonPath('data.user.uuid', $user->uuid)
            ->assertJsonPath('data.profile.business_name', 'Dual Divers')
            ->assertJsonPath('data.profile.city', 'Jeddah');
        $new = $response->json('data.token');
        $this->assertNotEmpty($new);
        $this->assertNotSame($old, $new);

        // The new token is a provider token...
        $this->asAccount($new)->getJson('/api/v1/provider/profile')->assertOk();
        $this->asAccount($new)->getJson('/api/v1/customer/profile')->assertForbidden();
        // ...and the one used to switch is gone.
        $this->asAccount($old)->getJson('/api/v1/customer/profile')->assertUnauthorized();
    }

    public function test_switching_back_to_the_customer_account(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $provider = $this->tokenFor($user, 'provider');

        $response = $this->asAccount($provider)->postJson('/api/v1/account/switch', ['account_type' => 'customer'])->assertOk()
            ->assertJsonPath('data.account.account_type', 'customer')
            ->assertJsonPath('data.profile.phone', $user->phone);

        $this->asAccount($response->json('data.token'))->getJson('/api/v1/customer/profile')->assertOk();
        $this->asAccount($response->json('data.token'))->getJson('/api/v1/provider/profile')->assertForbidden();
        $this->asAccount($provider)->getJson('/api/v1/provider/profile')->assertUnauthorized();
    }

    public function test_switching_only_ever_leaves_one_live_token_per_switch(): void
    {
        [$user] = $this->makeDualAccount('verified');
        $token = $this->tokenFor($user, 'customer');
        $before = $user->tokens()->count();

        $this->asAccount($token)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertOk();

        $this->assertSame($before, $user->tokens()->count(), 'One token was revoked and one issued.');
    }

    public function test_a_provider_who_never_used_the_customer_side_gets_a_customer_account_when_they_switch(): void
    {
        [$user] = $this->makeProvider('verified');
        $this->assertNull($user->customer);

        $this->asAccount($this->tokenFor($user, 'provider'))->postJson('/api/v1/account/switch', ['account_type' => 'customer'])->assertOk()
            ->assertJsonPath('data.account.account_type', 'customer');

        $this->assertNotNull($user->fresh()->customer, 'The customer account needs no approval, so it is created on first use.');
    }

    public function test_switching_to_the_account_you_are_already_in_is_harmless(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $response = $this->asAccount($this->tokenFor($user, 'customer'))->postJson('/api/v1/account/switch', ['account_type' => 'customer'])->assertOk();

        $this->asAccount($response->json('data.token'))->getJson('/api/v1/customer/profile')->assertOk();
    }

    public function test_switching_to_a_provider_account_that_is_not_approved_is_refused_and_changes_nothing(): void
    {
        [$customer] = $this->makeCustomer();
        [$waiting] = $this->makeDualAccount('pending');

        foreach ([$customer, $waiting] as $user) {
            $token = $this->tokenFor($user, 'customer');
            $tokens = $user->tokens()->count();

            $this->asAccount($token)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])
                ->assertStatus(422)->assertJsonValidationErrors('account_type');

            $this->assertSame($tokens, $user->tokens()->count(), 'A refused switch must not revoke the token in use.');
            $this->asAccount($token)->getJson('/api/v1/customer/profile')->assertOk();
        }
    }

    public function test_an_unknown_account_type_is_rejected(): void
    {
        [$user] = $this->makeCustomer();

        $this->asAccount($this->tokenFor($user, 'customer'))->postJson('/api/v1/account/switch', ['account_type' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('account_type');
    }

    public function test_the_logout_endpoint_revokes_the_token_in_use_for_either_account(): void
    {
        [$user] = $this->makeDualAccount('verified');

        foreach (['customer', 'provider'] as $account) {
            $token = $this->tokenFor($user, $account);

            $this->asAccount($token)->postJson('/api/v1/account/logout')->assertOk();
            $this->asAccount($token)->getJson('/api/v1/account')->assertUnauthorized();
        }
    }

    // --- Signing in with the same phone ---------------------------------------------------

    private function signIn(string $endpoint, string $phone, array $extra = [])
    {
        $this->postJson("/api/v1/auth/{$endpoint}/otp/request", ['phone' => $phone] + $extra)->assertSuccessful();

        return $this->postJson("/api/v1/auth/{$endpoint}/otp/verify", ['phone' => $phone, 'code' => config('otp.default_code')]);
    }

    public function test_the_customer_and_provider_sign_ins_open_the_account_they_are_named_for(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $asCustomer = $this->signIn('customer', $user->phone)->assertSuccessful();
        $asProvider = $this->signIn('provider', $user->phone)->assertSuccessful();

        $asCustomer->assertJsonPath('data.account.account_type', 'customer')->assertJsonPath('data.account.available_accounts', ['customer', 'provider']);
        $asProvider->assertJsonPath('data.account.account_type', 'provider')->assertJsonPath('data.user.user_type', 'customer');
        $this->asAccount($asProvider->json('data.token'))->getJson('/api/v1/provider/profile')->assertOk();
    }

    public function test_the_provider_sign_in_refuses_a_phone_that_has_no_provider_account(): void
    {
        [$customer] = $this->makeCustomer();

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => $customer->phone])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertStringContainsString('submit a provider request', $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => $customer->phone])->json('errors.phone.0'));
    }

    public function test_a_provider_that_is_not_approved_can_sign_in_but_is_told_where_it_stands(): void
    {
        [$user] = $this->makeProvider('pending');

        $this->signIn('provider', $user->phone)->assertSuccessful()
            ->assertJsonPath('data.account.account_type', 'provider')
            ->assertJsonPath('data.account.provider_request_status', 'not_submitted')
            ->assertJsonPath('data.account.available_accounts', ['customer']);
    }

    // --- Unified sign-in: opens the default account --------------------------------------------

    public function test_the_unified_sign_in_opens_the_default_account(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $first = $this->unified($user->phone)->assertSuccessful();
        $first->assertJsonPath('data.account.account_type', 'customer')->assertJsonPath('data.account.default_account', 'customer');

        $user->update(['default_account' => 'provider']);
        $second = $this->unified($user->phone)->assertSuccessful();
        $second->assertJsonPath('data.account.account_type', 'provider');
        $this->asAccount($second->json('data.token'))->getJson('/api/v1/provider/profile')->assertOk();
    }

    private function unified(string $phone)
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone])->assertSuccessful();

        return $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => config('otp.default_code')]);
    }

    public function test_the_unified_sign_in_for_a_provider_that_is_not_approved_opens_the_provider_side(): void
    {
        // So that someone who registered as a provider can finish or check their request.
        [$user] = $this->makeProvider('pending');

        $this->unified($user->phone)->assertSuccessful()->assertJsonPath('data.account.account_type', 'provider');
    }

    public function test_the_unified_sign_in_falls_back_when_the_chosen_default_is_no_longer_approved(): void
    {
        [$user,, $provider] = $this->makeDualAccount('verified');
        $user->update(['default_account' => 'provider']);
        $provider->update(['verification_status' => 'rejected']);

        $this->unified($user->phone)->assertSuccessful()->assertJsonPath('data.account.account_type', 'customer');
    }

    public function test_the_unified_sign_in_is_not_for_new_phones_or_staff(): void
    {
        $staff = User::factory()->admin()->create(['phone' => '0599000001']);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0599999999'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $staff->phone])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(0, Otp::whereIn('phone', ['0599999999', $staff->phone])->count(), 'No code may be issued.');
    }

    public function test_the_unified_sign_in_refuses_deleted_and_suspended_accounts_clearly(): void
    {
        [$deleted] = $this->makeCustomer(['phone' => '0599000002']);
        $deleted->delete();
        [$suspended] = $this->makeCustomer(['phone' => '0599000003']);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => $deleted->phone])->assertStatus(422);
        $this->assertStringContainsString('has been deleted', $this->postJson('/api/v1/auth/otp/request', ['phone' => $deleted->phone])->json('errors.phone.0'));

        $this->postJson('/api/v1/auth/otp/request', ['phone' => $suspended->phone])->assertSuccessful();
        $suspended->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => $suspended->phone, 'code' => config('otp.default_code')])->assertStatus(422);
    }

    public function test_a_registration_code_cannot_be_used_on_the_unified_sign_in(): void
    {
        $this->postJson('/api/v1/auth/customer/otp/request', ['phone' => '0599000004', 'name' => 'New Person'])->assertSuccessful();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '0599000004', 'code' => config('otp.default_code')])->assertStatus(422);

        $this->assertNull(User::where('phone', '0599000004')->first(), 'No account is created through the wrong door.');
    }

    public function test_the_unified_sign_in_can_resend_a_code_and_honours_the_cooldown(): void
    {
        [$user] = $this->makeCustomer();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $user->phone])->assertSuccessful();

        $this->postJson('/api/v1/auth/otp/resend', ['phone' => $user->phone])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_wrong_code_is_refused_on_the_unified_sign_in(): void
    {
        [$user] = $this->makeCustomer();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $user->phone])->assertSuccessful();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => $user->phone, 'code' => '9999'])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_registered_type_is_never_rewritten_by_any_of_this(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $this->asAccount($this->tokenFor($user, 'customer'))->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertOk();
        $this->asAccount($this->tokenFor($user, 'provider'))->putJson('/api/v1/account/default', ['default_account' => 'provider'])->assertOk();

        $this->assertSame('customer', $user->fresh()->user_type);
        $this->assertSame(1, Provider::where('user_id', $user->id)->count());
    }
}
