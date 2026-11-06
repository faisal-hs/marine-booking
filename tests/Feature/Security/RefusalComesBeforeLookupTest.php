<?php

namespace Tests\Feature\Security;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * A caller who is not allowed in must be refused BEFORE anything is looked up. Otherwise the answer depends on whether the id exists
 * (404 if not, 403 if so) and tells the caller which ids exist, drafts included. So for every kind of wrong caller the answer must be
 * the same whether the id is real or made up.
 */
class RefusalComesBeforeLookupTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const MISSING = '00000000-0000-4000-8000-000000000000';

    private function hit(?string $token, string $uri)
    {
        // Each call starts clean: withHeaders() would otherwise carry the previous caller's token into this one.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $headers = ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []);

        return $this->withHeaders($headers)->getJson($uri);
    }

    /** @return list<string> the provider endpoints that take an id, written with {a} for the activity and {b} for a booking option */
    private function providerUris(): array
    {
        return ['/api/v1/provider/activities/{a}', '/api/v1/provider/activities/{a}/booking-options', '/api/v1/provider/activities/{a}/booking-options/{b}',
            '/api/v1/provider/activities/{a}/booking-options/{b}/pricing-rules', '/api/v1/provider/activities/{a}/overrides', '/api/v1/provider/activities/{a}/reservations',
            '/api/v1/provider/activities/{a}/schedule'];
    }

    private function with(string $uri, string $activity): string
    {
        return str_replace(['{a}', '{b}'], [$activity, self::MISSING], $uri);
    }

    public function test_a_caller_who_may_not_use_the_provider_area_is_refused_the_same_way_for_a_real_id_and_a_made_up_one(): void
    {
        $this->seed(DatabaseSeeder::class);
        $real = DB::table('activities')->where('status', 'draft')->value('uuid');
        $this->assertNotNull($real, 'Expected a draft activity in the demo data: the one a wrong caller must not be able to detect');

        [$customer] = $this->makeCustomer();
        [, , $pendingToken] = $this->makeProvider('pending');
        $callers = ['a customer' => [$this->tokenFor($customer, 'customer'), 403], 'an unapproved provider' => [$pendingToken, 403], 'nobody signed in' => [null, 401]];

        foreach ($callers as $who => [$token, $expected]) {
            foreach ($this->providerUris() as $uri) {
                $this->assertSame($expected, $this->hit($token, $this->with($uri, $real))->status(), "{$who}, a real activity: {$uri}");
                $this->assertSame($expected, $this->hit($token, $this->with($uri, self::MISSING))->status(), "{$who}, a made-up activity: {$uri}");
            }
        }
    }

    public function test_a_provider_is_refused_on_the_customer_area_whether_or_not_the_id_exists(): void
    {
        $this->seed(DatabaseSeeder::class);
        [, , $providerToken] = $this->makeProvider('verified');

        foreach (['/api/v1/customer/reservations/'.self::MISSING, '/api/v1/customer/payments/'.self::MISSING] as $uri) {
            $this->assertSame(403, $this->hit($providerToken, $uri)->status(), $uri);
        }
    }

    public function test_an_approved_provider_still_gets_an_honest_404_for_something_that_is_not_there(): void
    {
        $this->seed(DatabaseSeeder::class);
        [, , $providerToken] = $this->makeProvider('verified');

        // The refusal is for people who are not allowed in; someone who is allowed in must still be told a thing does not exist.
        $this->assertSame(404, $this->hit($providerToken, '/api/v1/provider/activities/'.self::MISSING)->status());
        $this->assertSame(404, $this->hit($providerToken, '/api/v1/provider/activities/'.self::MISSING.'/schedule')->status());
    }
}
