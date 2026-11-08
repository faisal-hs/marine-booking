<?php

namespace Tests\Feature\ProviderApp;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * POST /provider/apply, GET /provider/apply and POST /provider/apply/resubmit (spec phase 1). It is the application an admin reviews: a rejection
 * stays in the history and a resubmission is a NEW application.
 */
class ProviderApplicationApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-06 13:00:00');
        Storage::fake(config('providers.documents.disk'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function form(array $overrides = []): array
    {
        return array_merge($this->providerRequestPayload(), [
            'business_name' => 'Abdullah Marine Services',
            'business_type' => 'Diving center',
            'category_ids' => [$this->makeCategory('Diving')->id, $this->makeCategory('Yacht')->id],
            'city' => 'Jeddah',
            'marina_name' => 'Obhur Marina',
            'area' => 'North Obhur',
            'phone' => '+966551112233',
            'email' => 'info@abdullahmarine.sa',
        ], $overrides);
    }

    /** An existing application of $provider, with its three documents on file, as the admin left it. */
    private function applicationOf(array $p, string $status, ?string $reason = null, string $submitted = '2026-10-20 11:00:00'): int
    {
        foreach (app(\App\Services\VerificationDocumentTypes::class)->mandatoryKeys() as $type) {
            DB::table('provider_documents')->insertOrIgnore(['uuid' => (string) Str::uuid(), 'provider_id' => $p['provider']->id, 'type' => $type, 'original_name' => "{$type}.pdf",
                'path' => "provider-documents/{$type}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('provider_requests')->insertGetId(['uuid' => (string) Str::uuid(), 'user_id' => $p['user']->id, 'provider_id' => $p['provider']->id,
            'source' => 'registration', 'status' => $status, 'submitted_at' => $submitted, 'reviewed_at' => $status === 'pending' ? null : '2026-10-21 09:00:00',
            'reviewed_by' => null, 'rejection_reason' => $reason, 'created_at' => $submitted, 'updated_at' => $submitted]);
    }

    // --- Applying ----------------------------------------------------------------------------------------------------

    public function test_a_provider_can_apply_and_everything_is_kept_for_the_admin(): void
    {
        $p = $this->unapprovedProvider('pending');
        $form = $this->form();

        $response = $this->hitWithFiles($p['token'], '/api/v1/provider/apply', $form)->assertCreated();

        $this->assertSame(['status' => true, 'message' => 'Application submitted'], array_intersect_key($response->json(), ['status' => 1, 'message' => 1]));
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertSame('2026-11-06T13:00:00Z', $response->json('data.submitted_at'));

        $provider = $p['provider']->refresh();
        $this->assertSame('Abdullah Marine Services', $provider->business_name);
        $this->assertSame(['Diving center', 'Obhur Marina', '+966551112233', 'info@abdullahmarine.sa'], [$provider->business_type, $provider->marina_name, $provider->contact_phone, $provider->contact_email]);
        $this->assertSame('pending', $provider->verification_status);
        $this->assertEqualsCanonicalizing($form['category_ids'], $provider->categories()->pluck('categories.id')->all(), 'Many categories, many to many');
        $this->assertSame(3, DB::table('provider_documents')->where('provider_id', $provider->id)->count());
        $this->assertSame(1, DB::table('provider_requests')->where('provider_id', $provider->id)->where('status', 'pending')->count());
        $this->assertSame($response->json('data.application_id'), (int) DB::table('provider_requests')->where('provider_id', $provider->id)->value('id'));
    }

    public function test_what_was_applied_with_is_visible_in_the_profile(): void
    {
        $p = $this->unapprovedProvider('pending');
        $form = $this->form();
        $this->hitWithFiles($p['token'], '/api/v1/provider/apply', $form)->assertCreated();

        $profile = $this->hit($p['token'], 'GET', '/api/v1/provider/profile')->assertOk()->json('data');

        $this->assertSame(['Diving center', 'Obhur Marina', '+966551112233', 'info@abdullahmarine.sa'], [$profile['business_type'], $profile['marina_name'], $profile['contact_phone'], $profile['contact_email']]);
        $this->assertEqualsCanonicalizing($form['category_ids'], $profile['category_ids']);
    }

    public function test_the_sign_in_phone_is_not_replaced_by_the_business_phone(): void
    {
        $p = $this->unapprovedProvider('pending');
        $signInPhone = $p['user']->phone;

        $this->hitWithFiles($p['token'], '/api/v1/provider/apply', $this->form(['phone' => '+966500000000']))->assertCreated();

        $this->assertSame($signInPhone, $p['user']->refresh()->phone);
    }

    public function test_every_required_field_and_document_is_required(): void
    {
        $p = $this->unapprovedProvider('pending');

        $response = $this->hitWithFiles($p['token'], '/api/v1/provider/apply', [])->assertStatus(422);

        $this->assertSame('VALIDATION_ERROR', $response->json('code'));
        foreach (['business_name', 'business_type', 'category_ids', 'city', 'area', 'marina_name', 'phone', 'email', 'commercial_registration', 'safety_certification', 'insurance_certificate'] as $field) {
            $this->assertArrayHasKey($field, $response->json('errors'), "{$field} should be required");
        }
        $this->assertSame(0, DB::table('provider_requests')->count());
    }

    public function test_categories_must_exist_be_active_and_be_listed_once(): void
    {
        $p = $this->unapprovedProvider('pending');
        $real = $this->makeCategory('Diving')->id;
        $inactive = $this->makeCategory('Closed');
        $inactive->update(['status' => 'inactive']);
        $removed = $this->makeCategory('Removed');
        $removed->delete();

        foreach ([[999999], [$inactive->id], [$removed->id], [$real, $real], 'not-an-array', []] as $bad) {
            $this->hitWithFiles($p['token'], '/api/v1/provider/apply', $this->form(['category_ids' => $bad]))->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['errors']);
        }
        $this->assertSame(0, DB::table('provider_requests')->count());
    }

    public function test_the_contact_details_must_look_right(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->hitWithFiles($p['token'], '/api/v1/provider/apply', $this->form(['email' => 'not-an-email', 'phone' => 'call me']))->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone']);
        $this->hitWithFiles($p['token'], '/api/v1/provider/apply', $this->form(['commercial_registration' => UploadedFile::fake()->create('cr.exe', 10, 'application/x-msdownload')]))->assertStatus(422)
            ->assertJsonValidationErrors('commercial_registration');
    }

    public function test_it_cannot_be_sent_twice_while_waiting_or_after_approval(): void
    {
        $waiting = $this->unapprovedProvider('pending');
        $this->applicationOf($waiting, 'pending');
        $this->hitWithFiles($waiting['token'], '/api/v1/provider/apply', $this->form())->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $approved = $this->approvedProvider();
        $this->hitWithFiles($approved['token'], '/api/v1/provider/apply', $this->form())->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $this->assertSame(1, DB::table('provider_requests')->count(), 'Nothing new was created');
    }

    public function test_a_customer_token_cannot_use_it(): void
    {
        [$customer] = $this->makeCustomer();

        $this->hitWithFiles($this->tokenFor($customer, 'customer'), '/api/v1/provider/apply', $this->form())->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    // --- Where the application stands ------------------------------------------------------------------------------------

    public function test_no_application_yet_is_a_404(): void
    {
        $this->hit($this->unapprovedProvider('pending')['token'], 'GET', '/api/v1/provider/apply')->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_a_waiting_application_shows_its_status_and_cannot_be_resubmitted(): void
    {
        $p = $this->unapprovedProvider('pending');
        $id = $this->applicationOf($p, 'pending');

        $this->hit($p['token'], 'GET', '/api/v1/provider/apply')->assertOk()->assertExactJson(['status' => true, 'message' => 'Success', 'data' => [
            'application_id' => $id, 'status' => 'pending', 'note' => null, 'submitted_at' => '2026-10-20T11:00:00Z', 'reviewed_at' => null, 'can_resubmit' => false]]);
    }

    public function test_a_rejected_application_shows_the_admins_note_and_can_be_resubmitted(): void
    {
        $p = $this->unapprovedProvider('rejected');
        $id = $this->applicationOf($p, 'rejected', 'Commercial registration file is not clear. Please upload it again.');

        $this->hit($p['token'], 'GET', '/api/v1/provider/apply')->assertOk()->assertJsonPath('data', [
            'application_id' => $id, 'status' => 'rejected', 'note' => 'Commercial registration file is not clear. Please upload it again.',
            'submitted_at' => '2026-10-20T11:00:00Z', 'reviewed_at' => '2026-10-21T09:00:00Z', 'can_resubmit' => true]);
    }

    public function test_it_shows_the_latest_application_and_only_your_own(): void
    {
        $p = $this->unapprovedProvider('rejected');
        $this->applicationOf($p, 'rejected', 'old', '2026-09-01 10:00:00');
        $latest = $this->applicationOf($p, 'pending', null, '2026-10-25 10:00:00');
        $other = $this->unapprovedProvider('pending');
        $this->applicationOf($other, 'rejected', 'someone else');

        $this->hit($p['token'], 'GET', '/api/v1/provider/apply')->assertOk()->assertJsonPath('data.application_id', $latest)->assertJsonPath('data.status', 'pending');
    }

    // --- Resubmitting ------------------------------------------------------------------------------------------------------

    public function test_a_rejected_application_is_resubmitted_as_a_new_one_and_the_history_is_kept(): void
    {
        $p = $this->unapprovedProvider('rejected');
        $p['provider']->update(['business_type' => 'Diving center', 'marina_name' => 'Obhur Marina', 'contact_phone' => '+966551112233', 'contact_email' => 'info@abdullahmarine.sa', 'city' => 'Jeddah', 'area' => 'North Obhur']);
        $categories = [$this->makeCategory('Diving')->id, $this->makeCategory('Yacht')->id];
        $p['provider']->categories()->sync($categories);
        $old = $this->applicationOf($p, 'rejected', 'Please upload it again.');

        $response = $this->hitWithFiles($p['token'], '/api/v1/provider/apply/resubmit', [
            'application_id' => $old, 'business_name' => 'Abdullah Marine Services (new name)',
            'commercial_registration' => UploadedFile::fake()->create('cr-clear.pdf', 50, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame('Application resubmitted', $response->json('message'));
        $this->assertSame($old, $response->json('data.previous_application_id'));
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertNotSame($old, $response->json('data.application_id'));

        $this->assertSame('rejected', DB::table('provider_requests')->where('id', $old)->value('status'), 'The rejected application stays as history');
        $this->assertSame('Please upload it again.', DB::table('provider_requests')->where('id', $old)->value('rejection_reason'));
        $this->assertSame(2, DB::table('provider_requests')->where('provider_id', $p['provider']->id)->count());

        $provider = $p['provider']->refresh();
        $this->assertSame('pending', $provider->verification_status, 'Back to waiting');
        $this->assertSame('Abdullah Marine Services (new name)', $provider->business_name, 'What was sent is applied');
        $this->assertSame(['Diving center', 'Obhur Marina', '+966551112233'], [$provider->business_type, $provider->marina_name, $provider->contact_phone], 'What was not sent is kept');
        $this->assertEqualsCanonicalizing($categories, $provider->categories()->pluck('categories.id')->all());
        // One of each is in force; the replaced commercial registration is kept as history (superseded), not deleted.
        $this->assertSame(3, DB::table('provider_documents')->where('provider_id', $provider->id)->whereNull('superseded_at')->count());
        $this->assertSame(4, DB::table('provider_documents')->where('provider_id', $provider->id)->count());
    }

    public function test_only_the_latest_rejected_application_can_be_resubmitted(): void
    {
        $p = $this->unapprovedProvider('rejected');
        $older = $this->applicationOf($p, 'rejected', 'old', '2026-09-01 10:00:00');
        $latest = $this->applicationOf($p, 'rejected', 'latest', '2026-10-01 10:00:00');

        $this->hitWithFiles($p['token'], '/api/v1/provider/apply/resubmit', ['application_id' => $older])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->hitWithFiles($p['token'], '/api/v1/provider/apply/resubmit', ['application_id' => 999999])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(2, DB::table('provider_requests')->count(), 'Nothing was created');
        $this->assertNotNull($latest);
    }

    public function test_a_waiting_or_approved_application_cannot_be_resubmitted_and_no_application_is_a_404(): void
    {
        $waiting = $this->unapprovedProvider('pending');
        $id = $this->applicationOf($waiting, 'pending');
        $this->hitWithFiles($waiting['token'], '/api/v1/provider/apply/resubmit', ['application_id' => $id])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $none = $this->unapprovedProvider('pending');
        $this->hitWithFiles($none['token'], '/api/v1/provider/apply/resubmit', ['application_id' => 1])->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->hitWithFiles($waiting['token'], '/api/v1/provider/apply/resubmit', [])->assertStatus(422)->assertJsonValidationErrors('application_id');
    }

    public function test_a_provider_with_no_details_on_file_must_send_them_when_resubmitting(): void
    {
        // Registered before these details existed: nothing to fall back on.
        $p = $this->unapprovedProvider('rejected');
        $id = $this->applicationOf($p, 'rejected', 'No.');

        $response = $this->hitWithFiles($p['token'], '/api/v1/provider/apply/resubmit', ['application_id' => $id])->assertStatus(422);

        foreach (['business_type', 'category_ids', 'marina_name', 'phone', 'email'] as $field) {
            $this->assertArrayHasKey($field, $response->json('errors'), $field);
        }
        $this->assertSame(1, DB::table('provider_requests')->count());
    }

    public function test_the_older_account_endpoint_still_works_without_the_new_fields(): void
    {
        [$user] = $this->makeCustomer();

        $this->hitWithFiles($this->tokenFor($user, 'customer'), '/api/v1/account/provider-request', $this->providerRequestPayload())->assertCreated();

        $this->assertNull(DB::table('providers')->where('user_id', $user->id)->value('business_type'));
        $this->assertSame(0, DB::table('category_provider')->count());
    }
}
