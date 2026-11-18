<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ProviderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/profile carries `notification`: how the provider wants to be told things, `email`, `sms`, `whatsapp` and `news_and_offers`, each true or false.
 * It is the same information as GET /provider/notification-preferences, which is how it is changed.
 */
class ProviderProfileNotificationTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const PROFILE = '/api/v1/provider/profile';

    private const PREFERENCES = '/api/v1/provider/notification-preferences';

    private const KEYS = ['email', 'sms', 'whatsapp', 'news_and_offers'];

    private array $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->approvedProvider();
    }

    private function profile(?array $as = null): array
    {
        return $this->hit(($as ?? $this->p)['token'], 'GET', self::PROFILE)->assertOk()->json('data');
    }

    // --- The block -------------------------------------------------------------------------------------------------------

    public function test_the_profile_has_the_four_notification_choices_each_true_or_false_and_by_default_email_and_news_are_on(): void
    {
        $profile = $this->profile();

        $this->assertSame(self::KEYS, array_keys($profile['notification']));
        $this->assertSame(['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true], $profile['notification']);
        foreach ($profile['notification'] as $key => $value) {
            $this->assertIsBool($value, $key);
        }
    }

    public function test_it_sits_with_the_rest_of_the_providers_information_before_the_account(): void
    {
        $keys = array_keys($this->profile());

        $this->assertSame('status', $keys[array_search('notification', $keys) - 1]);
        $this->assertSame('created_at', $keys[array_search('notification', $keys) + 1]);
        $this->assertSame('account', end($keys));
    }

    public function test_it_shows_what_the_provider_chose_each_choice_on_its_own(): void
    {
        foreach (self::KEYS as $key) {
            $this->hit($this->p['token'], 'PATCH', self::PREFERENCES, [$key => ! $this->profile()['notification'][$key]])->assertOk();
        }

        $this->assertSame(['email' => false, 'sms' => true, 'whatsapp' => true, 'news_and_offers' => false], $this->profile()['notification']);

        $this->hit($this->p['token'], 'PUT', self::PREFERENCES, ['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true])->assertOk();
        $this->assertSame(['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true], $this->profile()['notification']);
    }

    public function test_it_is_what_is_stored_for_the_provider_not_a_default(): void
    {
        DB::table('providers')->where('id', $this->p['provider']->id)->update(['notify_email' => 0, 'notify_sms' => 1, 'notify_whatsapp' => 1, 'notify_news_and_offers' => 0]);

        $this->assertSame(['email' => false, 'sms' => true, 'whatsapp' => true, 'news_and_offers' => false], $this->profile()['notification']);
    }

    public function test_it_is_always_exactly_what_the_preferences_endpoint_answers(): void
    {
        foreach ([['sms' => true], ['whatsapp' => true, 'email' => false], ['news_and_offers' => false]] as $change) {
            $this->hit($this->p['token'], 'PATCH', self::PREFERENCES, $change)->assertOk();

            $this->assertSame($this->hit($this->p['token'], 'GET', self::PREFERENCES)->json('data'), $this->profile()['notification']);
        }
    }

    public function test_one_providers_choices_are_not_anothers(): void
    {
        $other = $this->approvedProvider();

        $this->hit($this->p['token'], 'PATCH', self::PREFERENCES, ['sms' => true, 'whatsapp' => true, 'email' => false])->assertOk();

        $this->assertSame(['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true], $this->profile($other)['notification']);
        $this->assertSame(['email' => false, 'sms' => true, 'whatsapp' => true, 'news_and_offers' => true], $this->profile()['notification']);
    }

    // --- The same information wherever the profile appears -------------------------------------------------------------------

    public function test_the_answers_to_updating_the_profile_carry_it_too(): void
    {
        $this->hit($this->p['token'], 'PATCH', self::PREFERENCES, ['whatsapp' => true])->assertOk();
        $expected = ['email' => true, 'sms' => false, 'whatsapp' => true, 'news_and_offers' => true];

        $patch = $this->hit($this->p['token'], 'PATCH', self::PROFILE, ['bio' => 'We run dive trips since 2009.'])->assertOk();
        $put = $this->hit($this->p['token'], 'PUT', self::PROFILE, ['bio' => 'We run dive trips since 2010.'])->assertOk();

        $this->assertSame($expected, $patch->json('data.notification'));
        $this->assertSame($expected, $put->json('data.notification'));
    }

    public function test_the_provider_part_of_the_application_answer_carries_it_and_agrees_with_the_profile(): void
    {
        DB::table('provider_requests')->insert(['uuid' => (string) Str::uuid(), 'user_id' => $this->p['user']->id, 'provider_id' => $this->p['provider']->id, 'source' => ProviderRequest::SOURCE_REGISTRATION,
            'status' => 'approved', 'submitted_at' => now()->subDay(), 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->hit($this->p['token'], 'PATCH', self::PREFERENCES, ['sms' => true])->assertOk();

        $apply = $this->hit($this->p['token'], 'GET', '/api/v1/provider/apply')->assertOk()->json('data.provider');

        $this->assertSame($this->profile()['notification'], $apply['notification']);
        $this->assertTrue($apply['notification']['sms']);
    }

    public function test_the_account_answer_for_a_provider_carries_it_as_well(): void
    {
        $this->hit($this->p['token'], 'PATCH', self::PREFERENCES, ['whatsapp' => true])->assertOk();

        $account = $this->hit($this->p['token'], 'GET', '/api/v1/account')->assertOk()->json('data');
        $profile = collect($account)->first(fn ($value) => is_array($value) && array_key_exists('notification', $value));

        $this->assertNotNull($profile, 'The provider profile is in the account answer');
        $this->assertSame(['email' => true, 'sms' => false, 'whatsapp' => true, 'news_and_offers' => true], $profile['notification']);
    }

    // --- Who ---------------------------------------------------------------------------------------------------------

    public function test_a_customer_and_nobody_still_cannot_read_a_providers_profile(): void
    {
        [$customer] = $this->makeCustomer();

        $this->hit($this->tokenFor($customer, 'customer'), 'GET', self::PROFILE)->assertForbidden();
        $this->hit(null, 'GET', self::PROFILE)->assertUnauthorized();
    }
}
