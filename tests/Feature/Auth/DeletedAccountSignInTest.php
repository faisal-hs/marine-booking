<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * A deleted account is soft-deleted, so it still owns its phone number. Someone whose account
 * was deleted and who tried to sign in again used to be treated as a brand-new visitor, and
 * registration then collided with that phone: a raw SQL "unique constraint" error and an
 * HTTP 500, with the database error text in the response. It must be a clear refusal, before
 * any one-time code is sent, and restoring the account must let them back in.
 */
class DeletedAccountSignInTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const PHONE = '0544777888';

    /** @return array<string, array{0: string}> */
    public static function accountTypes(): array
    {
        return ['a customer' => ['customer'], 'a provider' => ['provider']];
    }

    private function makeAccount(string $type): User
    {
        if ($type === 'customer') {
            [$user] = $this->makeCustomer(['phone' => self::PHONE]);
        } else {
            [$user] = $this->makeProvider('verified');
            $user->update(['phone' => self::PHONE]);
        }

        return $user;
    }

    private function requestCode(string $type)
    {
        return $this->postJson("/api/v1/auth/{$type}/otp/request", ['phone' => self::PHONE, 'name' => 'Someone', 'owner_name' => 'Someone', 'business_name' => 'Some Co']);
    }

    private function verify(string $type)
    {
        return $this->postJson("/api/v1/auth/{$type}/otp/verify", ['phone' => self::PHONE, 'code' => config('otp.default_code')]);
    }

    #[DataProvider('accountTypes')]
    public function test_a_deleted_account_is_refused_clearly_before_any_code_is_sent(string $type): void
    {
        $this->makeAccount($type)->delete();

        $response = $this->requestCode($type);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->assertStringContainsString('has been deleted', $response->json('errors.phone.0'));
        $this->assertSame(0, DB::table('otps')->where('phone', self::PHONE)->count(), 'No one-time code may be issued for a deleted account.');
    }

    #[DataProvider('accountTypes')]
    public function test_a_code_issued_before_the_deletion_cannot_be_used_to_register_over_it(string $type): void
    {
        // A brand-new phone asks for a registration code...
        $this->requestCode($type)->assertSuccessful();
        // ...and before it is used, an account with that phone exists and is deleted.
        $user = $type === 'customer'
            ? User::factory()->customer()->create(['phone' => self::PHONE])
            : User::factory()->provider()->create(['phone' => self::PHONE]);
        $user->delete();

        $response = $this->verify($type);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent(), 'A database error must never reach the client.');
        $this->assertSame(1, User::withTrashed()->where('phone', self::PHONE)->count(), 'No second account may be created.');
    }

    #[DataProvider('accountTypes')]
    public function test_restoring_the_account_lets_them_sign_in_again(string $type): void
    {
        $user = $this->makeAccount($type);
        $user->delete();
        $this->requestCode($type)->assertStatus(422);

        $user->restore();

        $this->requestCode($type)->assertSuccessful();
        $login = $this->verify($type);
        $login->assertSuccessful();
        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame(1, User::withTrashed()->where('phone', self::PHONE)->count());
    }

    #[DataProvider('accountTypes')]
    public function test_a_suspended_account_is_still_refused_at_the_last_step(string $type): void
    {
        $this->makeAccount($type)->update(['status' => 'suspended']);

        $this->requestCode($type)->assertSuccessful();
        $this->verify($type)->assertStatus(422);
    }

    #[DataProvider('accountTypes')]
    public function test_an_ordinary_new_visitor_can_still_register(string $type): void
    {
        $this->requestCode($type)->assertSuccessful();

        $this->verify($type)->assertSuccessful();

        $this->assertSame($type === 'customer' ? 1 : 1, $type === 'customer' ? Customer::count() : Provider::count());
    }

    public function test_a_phone_that_belongs_to_a_live_account_of_the_other_kind_is_still_refused(): void
    {
        $this->makeAccount('customer');

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => self::PHONE, 'owner_name' => 'X', 'business_name' => 'Y'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }
}
