<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The admin JSON API (/api/v1/admin/*) used to be guarded by the SESSION guard, but the api middleware group has no
 * session, so over real HTTP every one of its routes answered 401 even for a signed-in admin. The other tests in
 * this suite never noticed, because they authenticate with actingAs(), which fakes the session.
 *
 * These tests use real bearer tokens and NO session, which is how a client would call it.
 */
class AdminApiTokenTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = $this->makeAdmin($role);
        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(self::PASSWORD)]);

        return $user->refresh();
    }

    private function signIn(User $user, string $password = self::PASSWORD)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/admin/login', ['email' => $user->email, 'password' => $password]);
    }

    private function tokenOf(User $user): string
    {
        return $this->signIn($user)->assertOk()->json('data.token');
    }

    private function api(?string $token, string $method, string $uri)
    {
        // A new request each time, as a client would make it: nothing carries over from the last call.
        $this->app['auth']->forgetGuards();
        $headers = ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []);

        return $this->withHeaders($headers)->json($method, $uri);
    }

    // --- Signing in ---------------------------------------------------------------------------

    public function test_staff_sign_in_and_receive_a_token_with_their_roles_and_permissions(): void
    {
        $staff = $this->staff('super_admin');

        $response = $this->signIn($staff)->assertOk()->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $staff->email)->assertJsonPath('data.user.roles', ['super_admin']);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertContains('reservations.view', $response->json('data.user.permissions'));
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, Carbon::parse($response->json('data.expires_at'))->timestamp, 5);
    }

    public function test_the_token_works_over_real_http_with_no_session_at_all(): void
    {
        $token = $this->tokenOf($this->staff('super_admin'));

        // This is the call that used to answer 401 for everybody.
        foreach (['/api/v1/admin/dashboard', '/api/v1/admin/reservations', '/api/v1/admin/payments', '/api/v1/admin/reviews', '/api/v1/admin/notifications'] as $uri) {
            $this->api($token, 'GET', $uri)->assertOk();
        }

        $this->assertGuest('web');
    }

    public function test_the_token_has_the_admin_ability_and_nothing_else(): void
    {
        $token = $this->tokenOf($this->staff('super_admin'));

        $this->assertSame(['admin'], json_decode(DB::table('personal_access_tokens')->value('abilities'), true));

        // So it opens nothing on the customer or provider side.
        $this->api($token, 'GET', '/api/v1/customer/reservations')->assertForbidden();
        $this->api($token, 'GET', '/api/v1/provider/activities')->assertForbidden();
    }

    // --- Who is turned away ---------------------------------------------------------------------

    public static function adminRoutes(): array
    {
        return ['dashboard' => ['GET', '/api/v1/admin/dashboard'], 'reservations' => ['GET', '/api/v1/admin/reservations'], 'payments' => ['GET', '/api/v1/admin/payments'],
            'reviews' => ['GET', '/api/v1/admin/reviews'], 'notifications' => ['GET', '/api/v1/admin/notifications'], 'refund' => ['POST', '/api/v1/admin/payments/x/refund']];
    }

    /** @dataProvider adminRoutes */
    #[DataProvider('adminRoutes')]
    public function test_without_a_token_the_admin_api_answers_401(string $method, string $uri): void
    {
        $this->api(null, $method, $uri)->assertUnauthorized();
    }

    /** @dataProvider adminRoutes */
    #[DataProvider('adminRoutes')]
    public function test_a_customer_or_provider_token_is_refused_by_the_admin_api(string $method, string $uri): void
    {
        [$customer] = $this->makeCustomer();
        [$provider, , $providerToken] = $this->makeProvider('verified');

        $this->api($this->tokenFor($customer, 'customer'), $method, $uri)->assertForbidden()->assertJsonPath('success', false);
        $this->api($providerToken, $method, $uri)->assertForbidden();
    }

    public function test_a_staff_members_token_without_the_admin_ability_is_refused(): void
    {
        $staff = $this->staff();

        // Valid, and belongs to real staff, but it is not an admin token (it could have been issued for anything else).
        $this->api($staff->createToken('other', ['customer'])->plainTextToken, 'GET', '/api/v1/admin/dashboard')->assertForbidden();
        $this->api($staff->createToken('wildcard', ['*'])->plainTextToken, 'GET', '/api/v1/admin/dashboard')->assertOk();
    }

    public function test_the_permission_still_decides_what_a_staff_member_may_do(): void
    {
        $super = $this->tokenOf($this->staff('super_admin'));
        $content = $this->tokenOf($this->staff('content_admin'));

        // Same door, different rights: a content admin has no business in payments.
        $this->api($super, 'GET', '/api/v1/admin/payments')->assertOk();
        $this->api($content, 'GET', '/api/v1/admin/payments')->assertForbidden();
    }

    public function test_wrong_passwords_customers_suspended_and_deleted_staff_cannot_sign_in(): void
    {
        $staff = $this->staff();
        $this->signIn($staff, 'not-the-password')->assertStatus(422)->assertJsonValidationErrors('email');

        [$customer] = $this->makeCustomer(['email' => 'customer@example.test']);
        $this->signIn($customer)->assertStatus(422);

        $suspended = $this->staff();
        DB::table('users')->where('id', $suspended->id)->update(['status' => 'suspended']);
        $this->signIn($suspended->refresh())->assertStatus(422);

        $deleted = $this->staff();
        $deleted->delete();
        $this->signIn($deleted)->assertStatus(422);

        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'None of them may have been given a token');
    }

    public function test_signing_in_is_rate_limited(): void
    {
        $staff = $this->staff();

        for ($i = 0; $i < 5; $i++) {
            $this->signIn($staff, 'guess-'.$i)->assertStatus(422);
        }

        $this->signIn($staff, 'guess-6')->assertStatus(429);
        // Even the right password, until the minute is up.
        $this->signIn($staff)->assertStatus(429);
    }

    // --- The life of a token -----------------------------------------------------------------------

    public function test_signing_out_revokes_the_token(): void
    {
        $token = $this->tokenOf($this->staff());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->api($token, 'POST', '/api/v1/auth/admin/logout')->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->api($token, 'GET', '/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_a_token_expires_after_the_configured_hours(): void
    {
        $token = $this->tokenOf($this->staff());

        $this->travel(7)->hours();
        $this->api($token, 'GET', '/api/v1/admin/dashboard')->assertOk();

        $this->travel(2)->hours();
        $this->api($token, 'GET', '/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_the_lifetime_comes_from_configuration(): void
    {
        config(['admin_api.token_hours' => 1]);
        $token = $this->tokenOf($this->staff());

        $this->travel(61)->minutes();

        $this->api($token, 'GET', '/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_a_staff_member_suspended_after_signing_in_loses_access_at_once(): void
    {
        $staff = $this->staff();
        $token = $this->tokenOf($staff);
        $this->api($token, 'GET', '/api/v1/admin/dashboard')->assertOk();

        DB::table('users')->where('id', $staff->id)->update(['status' => 'suspended']);

        $this->api($token, 'GET', '/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_the_browser_panel_still_works_with_its_session(): void
    {
        $staff = $this->staff();

        $this->post(route('admin.login'), ['email' => $staff->email, 'password' => self::PASSWORD])->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticatedAs($staff, 'web');
    }
}
