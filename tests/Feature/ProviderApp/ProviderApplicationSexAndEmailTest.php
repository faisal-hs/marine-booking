<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ProviderRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The provider's APPLICATION: the contact email is optional (a provider may have none), and the applicant may say their `sex` (male or female), which is kept on their account.
 * The same for a provider who registered, a customer who applies with the customer's token, a rejected application sent again, and the account-level request.
 */
class ProviderApplicationSexAndEmailTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const REQUIRED = ['commercial_registration', 'safety_certification', 'insurance_certificate'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function fields(array $over = []): array
    {
        $files = [];
        foreach (self::REQUIRED as $key) {
            $files[$key] = UploadedFile::fake()->create("{$key}.pdf", 40, 'application/pdf');
        }

        return array_merge(['business_name' => 'Abdullah Marine Services', 'business_type' => 'Diving center', 'category_ids' => [$this->makeCategory('Diving')->id], 'city' => 'Jeddah',
            'marina_name' => 'Obhur Marina', 'area' => 'North Obhur', 'phone' => '+966551112233', 'email' => 'info@abdullahmarine.sa'], $files, $over);
    }

    private function apply(string $token, array $over = [])
    {
        return $this->hitWithFiles($token, '/api/v1/provider/apply', $this->fields($over));
    }

    public function test_an_application_without_an_email_is_accepted_and_has_none(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p['token'], ['email' => null])->assertCreated();

        $this->assertNull($p['provider']->fresh()->contact_email);
        $this->assertNull($this->hit($p['token'], 'GET', '/api/v1/provider/apply')->assertOk()->json('data.provider.contact_email'));
    }

    public function test_leaving_the_email_out_or_sending_it_empty_is_the_same(): void
    {
        $left = $this->unapprovedProvider('pending');
        $empty = $this->unapprovedProvider('pending');

        $withoutKey = $this->fields();
        unset($withoutKey['email']);
        $this->hitWithFiles($left['token'], '/api/v1/provider/apply', $withoutKey)->assertCreated();
        $this->apply($empty['token'], ['email' => ''])->assertCreated();

        $this->assertNull($left['provider']->fresh()->contact_email);
        $this->assertNull($empty['provider']->fresh()->contact_email);
    }

    public function test_an_email_that_is_given_is_kept_and_must_still_be_an_email(): void
    {
        $bad = $this->unapprovedProvider('pending');
        $good = $this->unapprovedProvider('pending');

        $this->apply($bad['token'], ['email' => 'not an email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, ProviderRequest::count());

        $this->apply($good['token'])->assertCreated();
        $this->assertSame('info@abdullahmarine.sa', $good['provider']->fresh()->contact_email);
    }

    public function test_the_sex_given_with_the_application_is_kept_on_the_account(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p['token'], ['sex' => 'female'])->assertCreated();

        $this->assertSame('female', $p['user']->fresh()->sex);
        $this->assertSame('female', $this->hit($p['token'], 'GET', '/api/v1/provider/apply')->json('data.provider.sex'));
    }

    public function test_an_application_that_does_not_say_leaves_the_sex_as_it_was(): void
    {
        $p = $this->unapprovedProvider('pending');
        $p['user']->update(['sex' => 'male']);

        $this->apply($p['token'])->assertCreated();

        $this->assertSame('male', $p['user']->fresh()->sex);
    }

    public function test_a_sex_that_is_not_male_or_female_refuses_the_application_and_makes_nothing(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p['token'], ['sex' => 'other'])->assertStatus(422)->assertJsonValidationErrors('sex');

        $this->assertSame(0, ProviderRequest::count());
        $this->assertSame(0, DB::table('provider_documents')->count());
        $this->assertNull($p['user']->fresh()->sex);
    }

    public function test_a_customer_applies_with_their_own_token_without_an_email_and_says_their_sex(): void
    {
        [$user] = $this->makeCustomer(['sex' => null]);
        $token = $this->tokenFor($user, 'customer');

        $this->apply($token, ['email' => null, 'sex' => 'male'])->assertCreated();

        $this->assertSame('male', $user->fresh()->sex);
        $this->assertNull($user->fresh()->provider->contact_email);
        $this->assertSame(1, ProviderRequest::where('user_id', $user->id)->count());
    }

    public function test_a_rejected_application_can_be_sent_again_with_a_sex_and_with_no_email(): void
    {
        $p = $this->unapprovedProvider('pending');
        $this->apply($p['token'])->assertCreated();
        $application = ProviderRequest::firstOrFail();
        $application->update(['status' => 'rejected', 'rejection_reason' => 'Unclear', 'reviewed_at' => now()]);

        $this->hitWithFiles($p['token'], '/api/v1/provider/apply/resubmit', array_merge($this->fields(['email' => null, 'sex' => 'female']), ['application_id' => $application->id]))->assertCreated();

        $this->assertSame('female', $p['user']->fresh()->sex);
        $this->assertNull($p['provider']->fresh()->contact_email);
        $this->assertSame('pending', $p['provider']->fresh()->verification_status);
    }

    public function test_the_account_level_request_takes_the_sex_too(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $payload = $this->providerRequestPayload(['sex' => 'female']);

        $this->asAccount($token)->post('/api/v1/account/provider-request', array_merge($payload, ['sex' => 'other']), ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('sex');
        $this->assertNull($user->fresh()->sex);

        $this->asAccount($token)->post('/api/v1/account/provider-request', $payload, ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('female', User::find($user->id)->sex);
    }
}
