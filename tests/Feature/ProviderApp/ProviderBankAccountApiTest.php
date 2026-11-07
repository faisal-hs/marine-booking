<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** GET and PUT /provider/bank-account: the account withdrawals are paid into. The full IBAN is never returned and never stored in clear text. */
class ProviderBankAccountApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->approvedProvider();
    }

    private function save(array $data = [], ?array $as = null)
    {
        return $this->hit(($as ?? $this->a)['token'], 'PUT', '/api/v1/provider/bank-account', $data + ['account_holder_name' => 'Red Sea Divers', 'bank_name' => 'Al Rajhi Bank', 'iban' => 'sa03 8000 0000 6080 1016 7519']);
    }

    public function test_there_is_no_account_until_one_is_added(): void
    {
        $this->hit($this->a['token'], 'GET', '/api/v1/provider/bank-account')->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_it_is_saved_and_only_the_last_four_characters_come_back(): void
    {
        $response = $this->save()->assertOk();

        $this->assertSame('Bank account saved', $response->json('message'));
        $this->assertSame(['account_holder_name', 'bank_name', 'iban_last4', 'fee_percent', 'verification_status'], array_keys($response->json('data')));
        $this->assertSame(['Red Sea Divers', 'Al Rajhi Bank', '7519', 0, 'approved'], array_values($response->json('data')));
        $this->assertStringNotContainsString('80000000608010167519', $response->getContent(), 'The IBAN is not in the response');

        $again = $this->hit($this->a['token'], 'GET', '/api/v1/provider/bank-account')->assertOk();
        $this->assertSame($response->json('data'), $again->json('data'));
        $this->assertStringNotContainsString('8000', $again->getContent());
    }

    public function test_the_iban_is_stored_encrypted_and_in_capitals_without_spaces(): void
    {
        $this->save()->assertOk();

        $raw = DB::table('providers')->where('id', $this->a['provider']->id)->value('bank_account');

        $this->assertStringNotContainsString('SA0380000000608010167519', $raw, 'Not in clear text in the database');
        $this->assertStringNotContainsString('Al Rajhi', $raw);
        $this->assertSame('SA0380000000608010167519', Provider::find($this->a['provider']->id)->bank_account['iban']);
    }

    public function test_the_fee_the_app_shows_comes_from_the_configuration(): void
    {
        config(['wallet.withdrawal_fee_percent' => 2.5]);

        $this->assertSame(2.5, $this->save()->json('data.fee_percent'));
    }

    public function test_a_bad_account_is_refused(): void
    {
        foreach ([['iban' => 'not an iban'], ['iban' => '12345'], ['iban' => ''], ['account_holder_name' => ''], ['bank_name' => 'x'], ['swift_code' => 'nope']] as $bad) {
            $this->save($bad)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        }

        $this->save(['swift_code' => 'rjhisarixxx'])->assertOk();
    }

    public function test_each_provider_has_their_own_account(): void
    {
        $other = $this->approvedProvider();
        $this->save(['bank_name' => 'First Bank'])->assertOk();
        $this->save(['bank_name' => 'Second Bank', 'iban' => 'SA4420000001234567891234'], $other)->assertOk();

        $this->assertSame('First Bank', $this->hit($this->a['token'], 'GET', '/api/v1/provider/bank-account')->json('data.bank_name'));
        $this->assertSame('1234', $this->hit($other['token'], 'GET', '/api/v1/provider/bank-account')->json('data.iban_last4'));
    }

    public function test_an_unapproved_provider_a_customer_and_nobody_are_refused(): void
    {
        $this->save([], $this->unapprovedProvider())->assertForbidden()->assertJsonPath('code', 'PROVIDER_NOT_APPROVED');
        [$customer] = $this->makeCustomer();
        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/bank-account')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'GET', '/api/v1/provider/bank-account')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
