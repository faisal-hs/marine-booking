<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ProviderDocument;
use App\Models\VerificationDocumentType;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The documents that go with POST /provider/apply (and /apply/resubmit, and the account-level provider request), DYNAMIC: whatever document types an admin has switched on are the
 * keys the application takes, each REQUIRED or OPTIONAL as its type says. A document is sent as a field named after its key, or inside `documents[key]`. The three required types on a
 * fresh installation are commercial_registration, safety_certification and insurance_certificate.
 */
class ProviderApplicationDocumentsApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const APPLY = '/api/v1/provider/apply';

    private const REQUIRED = ['commercial_registration', 'safety_certification', 'insurance_certificate'];

    private const OPTIONAL = ['business_license', 'identity_document', 'professional_certification', 'insurance', 'other'];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-06 13:00:00');
        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fields(): array
    {
        return ['business_name' => 'Abdullah Marine Services', 'business_type' => 'Diving center', 'category_ids' => [$this->makeCategory('Diving')->id], 'city' => 'Jeddah',
            'marina_name' => 'Obhur Marina', 'area' => 'North Obhur', 'phone' => '+966551112233', 'email' => 'info@abdullahmarine.sa'];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create("{$name}.pdf", 40, 'application/pdf');
    }

    /** @param  list<string>  $keys */
    private function asFields(array $keys): array
    {
        return array_combine($keys, array_map(fn ($key) => $this->pdf($key), $keys));
    }

    private function apply(array $applicant, array $body)
    {
        return $this->hitWithFiles($applicant['token'], self::APPLY, array_merge($this->fields(), $body));
    }

    /** @return list<string> the document types on file for a provider */
    private function onFile(array $applicant): array
    {
        return $applicant['provider']->documents()->orderBy('id')->pluck('type')->all();
    }

    // --- Required documents ---------------------------------------------------------------------------------------------

    public function test_the_required_documents_alone_make_an_application_and_the_optional_ones_are_left_out(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, $this->asFields(self::REQUIRED))->assertCreated();

        $this->assertEqualsCanonicalizing(self::REQUIRED, $this->onFile($p));
    }

    public function test_a_required_document_that_is_missing_is_refused_by_its_key_and_nothing_is_kept(): void
    {
        $p = $this->unapprovedProvider('pending');

        foreach (self::REQUIRED as $missing) {
            $r = $this->apply($p, $this->asFields(array_diff(self::REQUIRED, [$missing])))->assertStatus(422);

            $this->assertArrayHasKey($missing, $r->json('errors'), "{$missing} is required");
        }

        $this->assertSame([], $this->onFile($p));
        $this->assertSame(0, DB::table('provider_requests')->count());
    }

    // --- Optional documents -----------------------------------------------------------------------------------------------

    public function test_every_document_type_can_be_sent_as_a_field_named_after_its_key_optional_ones_too(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, $this->asFields([...self::REQUIRED, ...self::OPTIONAL]))->assertCreated();

        $this->assertEqualsCanonicalizing([...self::REQUIRED, ...self::OPTIONAL], $this->onFile($p), 'The optional ones sent with the application are kept, not dropped');
        $this->assertSame(['pending'], $p['provider']->documents()->pluck('status')->unique()->values()->all());
    }

    public function test_an_optional_document_alone_with_the_required_ones_is_kept_and_the_rest_are_not_invented(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, $this->asFields([...self::REQUIRED, 'identity_document']))->assertCreated();

        $this->assertEqualsCanonicalizing([...self::REQUIRED, 'identity_document'], $this->onFile($p));
    }

    public function test_a_bad_optional_file_refuses_the_whole_application_and_keeps_nothing(): void
    {
        $p = $this->unapprovedProvider('pending');

        $r = $this->apply($p, $this->asFields(self::REQUIRED) + ['business_license' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422);

        $this->assertArrayHasKey('business_license', $r->json('errors'));
        $this->assertSame([], $this->onFile($p));
        $this->assertSame(0, DB::table('provider_requests')->count());
    }

    public function test_a_bad_optional_file_inside_documents_refuses_the_whole_application_too(): void
    {
        $p = $this->unapprovedProvider('pending');

        foreach ([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')] as $bad) {
            $r = $this->apply($p, ['documents' => $this->asFields(self::REQUIRED) + ['identity_document' => $bad]])->assertStatus(422);

            $this->assertArrayHasKey('documents.identity_document', $r->json('errors'));
        }

        $this->assertSame([], $this->onFile($p));
        $this->assertSame(0, DB::table('provider_requests')->count());
    }

    // --- documents[key] ---------------------------------------------------------------------------------------------------

    public function test_documents_can_be_sent_inside_documents_keyed_by_the_document_type(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, ['documents' => $this->asFields([...self::REQUIRED, 'business_license'])])->assertCreated();

        $this->assertEqualsCanonicalizing([...self::REQUIRED, 'business_license'], $this->onFile($p));
    }

    public function test_the_two_ways_of_sending_can_be_mixed_and_the_one_inside_documents_wins(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, [
            'commercial_registration' => UploadedFile::fake()->create('top-level.pdf', 40, 'application/pdf'),
            'documents' => ['commercial_registration' => UploadedFile::fake()->create('inside.pdf', 40, 'application/pdf'), 'safety_certification' => $this->pdf('s'), 'insurance_certificate' => $this->pdf('i')],
        ])->assertCreated();

        $this->assertSame('inside.pdf', $p['provider']->documents()->where('type', 'commercial_registration')->value('original_name'));
        $this->assertCount(3, $this->onFile($p));
    }

    public function test_a_required_document_sent_inside_documents_counts_as_sent(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, ['commercial_registration' => $this->pdf('cr'), 'documents' => $this->asFields(['safety_certification', 'insurance_certificate'])])->assertCreated();

        $this->assertEqualsCanonicalizing(self::REQUIRED, $this->onFile($p));
    }

    public function test_a_key_inside_documents_that_is_not_a_document_type_is_refused_and_named(): void
    {
        $p = $this->unapprovedProvider('pending');

        $r = $this->apply($p, ['documents' => $this->asFields([...self::REQUIRED, 'passport_of_the_cat'])])->assertStatus(422);

        $this->assertArrayHasKey('documents.passport_of_the_cat', $r->json('errors'));
        $this->assertStringContainsString('GET /provider/document-types lists them', (string) collect($r->json('errors'))->flatten()->first());
        $this->assertSame([], $this->onFile($p));
    }

    public function test_documents_that_is_not_an_array_is_refused(): void
    {
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, $this->asFields(self::REQUIRED) + ['documents' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('documents');
    }

    // --- Dynamic: what the admin decides ------------------------------------------------------------------------------------

    public function test_what_is_required_follows_the_admin_a_type_made_mandatory_is_asked_for(): void
    {
        VerificationDocumentType::where('key', 'business_license')->update(['is_mandatory' => true]);
        $p = $this->unapprovedProvider('pending');

        $r = $this->apply($p, $this->asFields(self::REQUIRED))->assertStatus(422);
        $this->assertArrayHasKey('business_license', $r->json('errors'));

        $this->apply($p, $this->asFields([...self::REQUIRED, 'business_license']))->assertCreated();
        $this->assertContains('business_license', $this->onFile($p));
    }

    public function test_a_type_made_optional_is_no_longer_asked_for(): void
    {
        VerificationDocumentType::where('key', 'commercial_registration')->update(['is_mandatory' => false]);
        $p = $this->unapprovedProvider('pending');

        $this->apply($p, $this->asFields(['safety_certification', 'insurance_certificate']))->assertCreated();

        $this->assertEqualsCanonicalizing(['safety_certification', 'insurance_certificate'], $this->onFile($p));
    }

    public function test_a_type_switched_off_is_neither_asked_for_nor_read(): void
    {
        VerificationDocumentType::where('key', 'safety_certification')->update(['is_active' => false]);   // a REQUIRED type
        VerificationDocumentType::where('key', 'other')->update(['is_active' => false]);                  // an optional one
        $p = $this->unapprovedProvider('pending');

        // safety_certification is not sent at all: switched off, it is no longer required. A file sent for `other` is not kept.
        $this->apply($p, $this->asFields(['commercial_registration', 'insurance_certificate', 'other']))->assertCreated();

        $this->assertEqualsCanonicalizing(['commercial_registration', 'insurance_certificate'], $this->onFile($p), 'A file sent for a switched-off type is not kept');

        $q = $this->unapprovedProvider('pending');
        $this->apply($q, ['documents' => $this->asFields(['commercial_registration', 'insurance_certificate', 'other'])])->assertStatus(422)->assertJsonValidationErrors('documents.other');
    }

    public function test_a_brand_new_type_an_admin_adds_is_taken_at_once(): void
    {
        VerificationDocumentType::create(['key' => 'dive_master_card', 'title_en' => 'Dive master card', 'title_ar' => 'بطاقة قائد الغوص', 'is_mandatory' => true, 'is_active' => true, 'sort_order' => 99]);
        $p = $this->unapprovedProvider('pending');

        $this->assertArrayHasKey('dive_master_card', $this->apply($p, $this->asFields(self::REQUIRED))->assertStatus(422)->json('errors'));

        $this->apply($p, $this->asFields([...self::REQUIRED, 'dive_master_card']))->assertCreated();
        $this->assertContains('dive_master_card', $this->onFile($p));
    }

    // --- The list tells an applicant what to send ------------------------------------------------------------------------------

    public function test_a_provider_who_has_registered_but_not_applied_can_read_the_list_and_every_document_is_not_uploaded(): void
    {
        $p = $this->unapprovedProvider('pending');
        $this->assertSame(0, DB::table('provider_requests')->count(), 'No application yet');

        $rows = $this->hit($p['token'], 'GET', '/api/v1/provider/document-types')->assertOk()->json('data');

        $this->assertSame([...self::REQUIRED, ...self::OPTIONAL], array_column($rows, 'key'));
        $this->assertSame(['not_uploaded'], array_unique(array_column($rows, 'status')));
        $this->assertSame(self::REQUIRED, array_column(array_filter($rows, fn ($r) => $r['requirement'] === 'required'), 'key'));
        $this->assertSame([true], array_unique(array_column($rows, 'can_update')));
        $this->assertSame([null], array_unique(array_column($rows, 'file_url')));
    }

    public function test_an_app_can_drive_the_application_from_that_list_alone(): void
    {
        VerificationDocumentType::create(['key' => 'dive_master_card', 'title_en' => 'Dive master card', 'title_ar' => 'بطاقة قائد الغوص', 'is_mandatory' => true, 'is_active' => true, 'sort_order' => 99]);
        VerificationDocumentType::where('key', 'identity_document')->update(['is_active' => false]);
        $p = $this->unapprovedProvider('pending');

        // The app asks which documents there are, and sends exactly those, whatever the admin has configured: the required ones, and an optional one.
        $rows = $this->hit($p['token'], 'GET', '/api/v1/provider/document-types')->assertOk()->json('data');
        $keys = array_column($rows, 'key');
        $this->assertNotContains('identity_document', $keys);
        $this->assertContains('dive_master_card', $keys);
        $send = array_column(array_filter($rows, fn ($r) => $r['requirement'] === 'required' || $r['key'] === 'business_license'), 'key');

        $this->apply($p, ['documents' => $this->asFields($send)])->assertCreated();

        $after = collect($this->hit($p['token'], 'GET', '/api/v1/provider/document-types')->json('data'))->keyBy('key');
        foreach ($send as $key) {
            $this->assertSame('pending', $after[$key]['status'], $key);
        }
        $this->assertSame('not_uploaded', $after['professional_certification']['status']);
    }

    // --- A rejected application sent again ---------------------------------------------------------------------------------

    public function test_a_resubmission_takes_optional_documents_too_and_needs_no_resending_of_those_on_file(): void
    {
        $p = $this->unapprovedProvider('rejected');
        foreach (self::REQUIRED as $type) {
            DB::table('provider_documents')->insert(['uuid' => (string) Str::uuid(), 'provider_id' => $p['provider']->id, 'type' => $type, 'original_name' => "{$type}.pdf", 'path' => "provider-documents/{$type}.pdf",
                'mime_type' => 'application/pdf', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        $application = DB::table('provider_requests')->insertGetId(['uuid' => (string) Str::uuid(), 'user_id' => $p['user']->id, 'provider_id' => $p['provider']->id, 'source' => 'registration',
            'status' => 'rejected', 'submitted_at' => '2026-10-20 11:00:00', 'reviewed_at' => '2026-10-21 09:00:00', 'rejection_reason' => 'Unclear', 'created_at' => now(), 'updated_at' => now()]);
        $before = $p['provider']->documents()->count();

        $this->hitWithFiles($p['token'], self::APPLY.'/resubmit', array_merge($this->fields(), ['application_id' => $application, 'documents' => $this->asFields(['identity_document', 'business_license'])]))->assertCreated();

        $this->assertSame($before + 2, $p['provider']->documents()->count(), 'The two optional documents were kept, the three required ones were already there');
        $this->assertContains('identity_document', $this->onFile($p));
    }

    // --- The account-level request is the same ------------------------------------------------------------------------------------

    public function test_the_account_level_request_takes_optional_documents_and_refuses_an_unknown_key_too(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $payload = $this->providerRequestPayload(['identity_document' => $this->pdf('id')]);

        $bad = $this->asAccount($token)->post('/api/v1/account/provider-request', $this->providerRequestPayload(['documents' => ['passport_of_the_cat' => $this->pdf('x')]]), ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertArrayHasKey('documents.passport_of_the_cat', $bad->json('errors'));
        $this->assertSame(0, ProviderDocument::count());

        $this->asAccount($token)->post('/api/v1/account/provider-request', $payload, ['Accept' => 'application/json'])->assertCreated();

        $this->assertEqualsCanonicalizing([...self::REQUIRED, 'identity_document'], ProviderDocument::pluck('type')->all());
    }
}
