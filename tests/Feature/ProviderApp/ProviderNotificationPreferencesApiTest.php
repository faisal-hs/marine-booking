<?php

namespace Tests\Feature\ProviderApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * How a provider wants to be told things: by email, by SMS, by WhatsApp, and whether they want news and offers (/provider/notification-preferences).
 */
class ProviderNotificationPreferencesApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const URL = '/api/v1/provider/notification-preferences';

    private const DEFAULTS = ['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true];

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->approvedProvider();
    }

    private function show(?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'GET', self::URL);
    }

    private function change(array $body, string $method = 'PATCH', ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], $method, self::URL, $body);
    }

    private function stored(?array $as = null): array
    {
        $row = DB::table('providers')->where('id', ($as ?? $this->a)['provider']->id)->first(['notify_email', 'notify_sms', 'notify_whatsapp', 'notify_news_and_offers']);

        return ['email' => (bool) $row->notify_email, 'sms' => (bool) $row->notify_sms, 'whatsapp' => (bool) $row->notify_whatsapp, 'news_and_offers' => (bool) $row->notify_news_and_offers];
    }

    // --- Reading ---------------------------------------------------------------------------------------------------

    public function test_a_new_provider_has_email_and_news_on_and_sms_and_whatsapp_off_in_this_shape(): void
    {
        $r = $this->show()->assertOk();

        $this->assertSame(['status', 'message', 'data'], array_keys($r->json()));
        $this->assertSame(['email', 'sms', 'whatsapp', 'news_and_offers'], array_keys($r->json('data')));
        $this->assertSame(self::DEFAULTS, $r->json('data'));
        $this->assertTrue($r->json('status'));
    }

    // --- Changing --------------------------------------------------------------------------------------------------------

    public function test_one_preference_changes_alone_and_the_answer_is_all_four(): void
    {
        $r = $this->change(['whatsapp' => true])->assertOk();

        $expected = array_merge(self::DEFAULTS, ['whatsapp' => true]);
        $this->assertSame($expected, $r->json('data'));
        $this->assertSame($expected, $this->stored());
        $this->assertSame($expected, $this->show()->json('data'), 'And it is what is read back');
    }

    public function test_all_four_can_be_set_at_once_to_true_or_to_false(): void
    {
        $this->assertSame(['email' => false, 'sms' => true, 'whatsapp' => true, 'news_and_offers' => false], $this->change(['email' => false, 'sms' => true, 'whatsapp' => true, 'news_and_offers' => false])->assertOk()->json('data'));
        $this->assertSame(['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true], $this->change(['email' => true, 'sms' => false, 'whatsapp' => false, 'news_and_offers' => true], 'PUT')->assertOk()->json('data'));
    }

    public function test_put_and_patch_are_the_same_update(): void
    {
        $this->change(['sms' => true], 'PUT')->assertOk();
        $this->assertTrue($this->stored()['sms']);

        $this->change(['sms' => false], 'PATCH')->assertOk();
        $this->assertFalse($this->stored()['sms']);
    }

    public function test_a_form_may_send_the_words_true_and_false_or_one_and_zero(): void
    {
        $r = $this->change(['email' => 'false', 'sms' => 'true', 'whatsapp' => 1, 'news_and_offers' => '0'])->assertOk();

        $this->assertSame(['email' => false, 'sms' => true, 'whatsapp' => true, 'news_and_offers' => false], $r->json('data'));
    }

    public function test_sending_the_same_thing_again_is_harmless(): void
    {
        $this->change(['sms' => true])->assertOk();
        $this->change(['sms' => true])->assertOk();

        $this->assertSame(array_merge(self::DEFAULTS, ['sms' => true]), $this->stored());
    }

    public function test_a_field_that_is_not_one_of_the_four_is_ignored_and_nothing_else_about_the_provider_changes(): void
    {
        $name = $this->a['provider']->business_name;

        $this->change(['sms' => true, 'business_name' => 'Hacked Co', 'status' => 'suspended', 'commission_rate' => 0])->assertOk();

        $provider = $this->a['provider']->refresh();
        $this->assertSame([$name, true], [$provider->business_name, $this->stored()['sms']]);
        $this->assertNotSame('suspended', $provider->status);
    }

    // --- What is refused -------------------------------------------------------------------------------------------------

    public function test_a_value_that_is_not_true_or_false_is_refused_and_nothing_changes(): void
    {
        foreach (['maybe', null, 'yes', 7, [], ['x']] as $bad) {
            $r = $this->change(['sms' => true, 'email' => $bad])->assertStatus(422);

            $this->assertFalse($r->json('status'));
            $this->assertArrayHasKey('email', $r->json('errors'));
        }

        $this->assertSame(self::DEFAULTS, $this->stored(), 'Not even the valid field next to the bad one was applied');
    }

    public function test_an_empty_request_or_one_with_no_preference_in_it_is_refused(): void
    {
        foreach ([[], ['business_name' => 'x']] as $body) {
            $r = $this->change($body)->assertStatus(422);

            $this->assertArrayHasKey('body', $r->json('errors'));
            $this->assertStringContainsString('Send at least one of: email, sms, whatsapp, news_and_offers', $r->json('errors.body.0'));
        }

        $this->assertSame(self::DEFAULTS, $this->stored());
    }

    // --- Who ---------------------------------------------------------------------------------------------------------

    public function test_a_provider_who_is_not_approved_yet_can_say_how_they_want_to_be_reached(): void
    {
        $applicant = $this->unapprovedProvider('pending');

        $this->assertSame(self::DEFAULTS, $this->show($applicant)->assertOk()->json('data'));
        $this->assertTrue($this->change(['whatsapp' => true], 'PATCH', $applicant)->assertOk()->json('data.whatsapp'));
        $this->assertTrue($this->stored($applicant)['whatsapp']);
    }

    public function test_one_providers_preferences_are_not_anothers(): void
    {
        $other = $this->approvedProvider();

        $this->change(['sms' => true, 'email' => false])->assertOk();

        $this->assertSame(self::DEFAULTS, $this->stored($other));
        $this->assertSame(self::DEFAULTS, $this->show($other)->json('data'));
    }

    public function test_a_customer_and_nobody_may_not_read_or_change_them(): void
    {
        [$customer] = $this->makeCustomer();
        $token = $this->tokenFor($customer, 'customer');

        $this->hit($token, 'GET', self::URL)->assertForbidden();
        $this->hit($token, 'PATCH', self::URL, ['sms' => true])->assertForbidden();
        $this->hit(null, 'GET', self::URL)->assertUnauthorized();
        $this->hit(null, 'PATCH', self::URL, ['sms' => true])->assertUnauthorized();
        $this->assertSame(self::DEFAULTS, $this->stored());
    }
}
