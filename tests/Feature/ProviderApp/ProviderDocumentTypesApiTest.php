<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ProviderDocument;
use App\Models\User;
use App\Models\VerificationDocumentType;
use App\Services\ProviderApp\ProviderDocumentTypesView;
use App\Services\ProviderDocumentReviewService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/document-types (every type: its key, required or optional, and where the provider's own stands in four words: not_uploaded, pending, need_update, accepted)
 * and PUT/PATCH/POST /provider/document-types (update one or all of the provider's documents, all or nothing).
 */
class ProviderDocumentTypesApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const URL = '/api/v1/provider/document-types';

    private const KEYS = ['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license', 'identity_document', 'professional_certification', 'insurance', 'other'];

    private const REQUIRED = ['commercial_registration', 'safety_certification', 'insurance_certificate'];

    private array $p;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->p = $this->approvedProvider();
        $this->admin = $this->makeAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pdf(string $name = 'doc'): UploadedFile
    {
        return UploadedFile::fake()->create("{$name}.pdf", 40, 'application/pdf');
    }

    /** @param  list<string>  $keys */
    private function files(array $keys): array
    {
        return array_combine($keys, array_map(fn ($key) => $this->pdf($key), $keys));
    }

    private function types(?array $as = null): array
    {
        return collect($this->hit(($as ?? $this->p)['token'], 'GET', self::URL)->assertOk()->json('data'))->keyBy('key')->all();
    }

    private function update(array $documents, string $method = 'PUT', ?array $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->call($method, self::URL, [], [], ['documents' => $documents], ['HTTP_AUTHORIZATION' => 'Bearer '.($as ?? $this->p)['token'], 'HTTP_ACCEPT' => 'application/json']);
    }

    private function current(string $key): ?ProviderDocument
    {
        return $this->p['provider']->documents()->where('type', $key)->orderByDesc('id')->first();
    }

    private function review(): ProviderDocumentReviewService
    {
        return app(ProviderDocumentReviewService::class);
    }

    private function storedFiles(): int
    {
        return count(Storage::disk(config('providers.documents.disk'))->allFiles());
    }

    // --- The list --------------------------------------------------------------------------------------------------

    public function test_every_type_has_its_key_whether_it_is_required_and_a_status_in_this_shape(): void
    {
        $r = $this->hit($this->p['token'], 'GET', self::URL)->assertOk();

        $this->assertSame(['status', 'message', 'data'], array_keys($r->json()));
        $this->assertSame(self::KEYS, array_column($r->json('data'), 'key'));
        foreach ($r->json('data') as $row) {
            $this->assertSame(['key', 'title', 'title_ar', 'title_en', 'description', 'requirement', 'status', 'need_update_reason', 'review_note', 'uploaded_at', 'expires_at', 'can_update',
                'file_url', 'accepted_formats', 'max_size_kb'], array_keys($row));
            $this->assertSame(['pdf', 'jpg', 'jpeg', 'png', 'webp'], $row['accepted_formats']);
            $this->assertSame(5120, $row['max_size_kb']);
        }
    }

    public function test_three_are_required_and_five_optional_and_it_follows_what_the_admin_decides(): void
    {
        $rows = $this->types();

        $this->assertSame(self::REQUIRED, collect($rows)->where('requirement', 'required')->keys()->all());
        $this->assertSame(['optional'], collect($rows)->except(self::REQUIRED)->pluck('requirement')->unique()->values()->all());

        VerificationDocumentType::where('key', 'business_license')->update(['is_mandatory' => true]);
        VerificationDocumentType::where('key', 'commercial_registration')->update(['is_mandatory' => false]);
        $changed = $this->types();
        $this->assertSame(['required', 'optional'], [$changed['business_license']['requirement'], $changed['commercial_registration']['requirement']]);

        VerificationDocumentType::where('key', 'other')->update(['is_active' => false]);
        $this->assertArrayNotHasKey('other', $this->types(), 'A type the admin switched off is not asked for');
    }

    public function test_a_provider_who_has_sent_nothing_has_every_document_as_not_uploaded(): void
    {
        foreach ($this->types() as $key => $row) {
            $this->assertSame(['not_uploaded', null, null, null, null, true, null], [$row['status'], $row['need_update_reason'], $row['review_note'], $row['uploaded_at'], $row['expires_at'], $row['can_update'], $row['file_url']], $key);
        }
    }

    public function test_a_document_that_was_sent_is_pending_and_one_an_admin_approved_is_accepted(): void
    {
        $this->update($this->files(['commercial_registration', 'safety_certification']))->assertOk();
        $this->review()->approve($this->current('safety_certification'), $this->admin);

        $rows = $this->types();

        $this->assertSame(['pending', true, '2026-11-15T12:00:00Z'], [$rows['commercial_registration']['status'], $rows['commercial_registration']['can_update'], $rows['commercial_registration']['uploaded_at']]);
        $this->assertSame(['accepted', false], [$rows['safety_certification']['status'], $rows['safety_certification']['can_update']], 'An accepted document stays as it is');
        $this->assertSame('not_uploaded', $rows['insurance_certificate']['status']);
        $this->assertStringContainsString('signature=', $rows['commercial_registration']['file_url']);
    }

    public function test_rejected_expired_and_update_requested_are_all_need_update_and_say_which(): void
    {
        $this->update($this->files(['commercial_registration', 'safety_certification', 'insurance_certificate']))->assertOk();
        $this->review()->reject($this->current('commercial_registration'), $this->admin, 'The scan is not clear');
        $this->review()->approve($this->current('safety_certification'), $this->admin, Carbon::parse('2026-11-10'));   // approved, but only until the 10th: expired now
        $this->review()->approve($this->current('insurance_certificate'), $this->admin);
        $this->review()->requestUpdate($this->p['provider'], 'insurance_certificate', $this->admin, 'The policy was renewed');

        $rows = $this->types();

        $this->assertSame(['need_update', 'rejected', 'The scan is not clear', true], [$rows['commercial_registration']['status'], $rows['commercial_registration']['need_update_reason'], $rows['commercial_registration']['review_note'], $rows['commercial_registration']['can_update']]);
        $this->assertSame(['need_update', 'expired', false === ($rows['safety_certification']['expires_at'] === null) ? '2026-11-10' : null], [$rows['safety_certification']['status'], $rows['safety_certification']['need_update_reason'], $rows['safety_certification']['expires_at']]);
        $this->assertSame(['need_update', 'update_requested', 'The policy was renewed'], [$rows['insurance_certificate']['status'], $rows['insurance_certificate']['need_update_reason'], $rows['insurance_certificate']['review_note']]);
    }

    public function test_it_is_always_the_same_document_status_as_the_verification_endpoint_in_other_words(): void
    {
        $this->update($this->files(['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license']))->assertOk();
        $this->review()->reject($this->current('commercial_registration'), $this->admin, 'No');
        $this->review()->approve($this->current('safety_certification'), $this->admin, Carbon::parse('2026-11-10'));
        $this->review()->approve($this->current('insurance_certificate'), $this->admin);
        $this->review()->requestUpdate($this->p['provider'], 'insurance_certificate', $this->admin, 'Renewed');
        $this->review()->approve($this->current('business_license'), $this->admin);

        $verification = collect($this->hit($this->p['token'], 'GET', '/api/v1/provider/verification')->assertOk()->json('data'))->keyBy('key');

        foreach ($this->types() as $key => $row) {
            $this->assertSame(ProviderDocumentTypesView::simple($verification[$key]['status']), $row['status'], $key);
            $this->assertSame($verification[$key]['is_mandatory'] ? 'required' : 'optional', $row['requirement'], $key);
            $this->assertSame($verification[$key]['can_upload'], $row['can_update'], $key);
        }
        $this->assertSame(['need_update', 'need_update', 'need_update', 'accepted', 'not_uploaded'], [$this->types()['commercial_registration']['status'], $this->types()['safety_certification']['status'],
            $this->types()['insurance_certificate']['status'], $this->types()['business_license']['status'], $this->types()['other']['status']]);
    }

    public function test_the_title_is_in_the_requests_language_and_both_languages_are_always_there(): void
    {
        $english = $this->types();
        app()->setLocale('ar');
        $arabic = $this->types();
        app()->setLocale('en');

        foreach (self::KEYS as $key) {
            $this->assertSame([$english[$key]['title_ar'], $english[$key]['title_en']], [$arabic[$key]['title_ar'], $arabic[$key]['title_en']]);
            $this->assertSame($english[$key]['title_en'], $english[$key]['title']);
            $this->assertSame($arabic[$key]['title_ar'], $arabic[$key]['title']);
        }
    }

    // --- Updating one or all ---------------------------------------------------------------------------------------------

    public function test_one_document_can_be_updated_alone(): void
    {
        $r = $this->update($this->files(['insurance_certificate']))->assertOk();

        $this->assertSame(['status', 'message', 'data'], array_keys($r->json()));
        $this->assertSame('Document updated and sent for review.', $r->json('message'));
        $this->assertSame(['updated', 'required_remaining', 'all_required_provided', 'documents'], array_keys($r->json('data')));
        $this->assertSame([['key' => 'insurance_certificate', 'status' => 'pending', 'updated_at' => '2026-11-15T12:00:00Z']], $r->json('data.updated'));
        $this->assertSame(['commercial_registration', 'safety_certification'], $r->json('data.required_remaining'));
        $this->assertFalse($r->json('data.all_required_provided'));
        $this->assertSame('pending', $this->types()['insurance_certificate']['status']);
        $this->assertSame('not_uploaded', $this->types()['commercial_registration']['status'], 'Not sent, so left as it was');
    }

    public function test_all_the_documents_can_be_updated_at_once_and_the_answer_is_the_whole_list(): void
    {
        $r = $this->update($this->files(self::KEYS), 'PATCH')->assertOk();

        $this->assertSame('8 documents updated and sent for review.', $r->json('message'));
        $this->assertSame(self::KEYS, array_column($r->json('data.updated'), 'key'), 'In the order sent');
        $this->assertSame([[], true], [$r->json('data.required_remaining'), $r->json('data.all_required_provided')]);
        $this->assertSame(array_values($this->types()), $r->json('data.documents'), 'The same rows as GET /provider/document-types');
        $this->assertSame(['pending'], collect($r->json('data.documents'))->pluck('status')->unique()->values()->all());
        $this->assertSame(8, $this->storedFiles());
    }

    public function test_put_patch_and_post_are_the_same_update(): void
    {
        foreach (['PUT' => 'commercial_registration', 'PATCH' => 'safety_certification', 'POST' => 'insurance_certificate'] as $method => $key) {
            $r = $this->update($this->files([$key]), $method)->assertOk();

            $this->assertSame($key, $r->json('data.updated.0.key'), $method);
            $this->assertSame('pending', $this->types()[$key]['status'], $method);
        }
        $this->assertSame(3, $this->storedFiles());
    }

    public function test_documents_that_need_updating_are_replaced_and_the_old_versions_are_kept(): void
    {
        $this->update($this->files(self::REQUIRED))->assertOk();
        $this->review()->reject($this->current('commercial_registration'), $this->admin, 'Blurry');
        $this->review()->approve($this->current('safety_certification'), $this->admin);
        $this->review()->requestUpdate($this->p['provider'], 'safety_certification', $this->admin, 'New name');
        $before = DB::table('provider_documents')->count();

        $this->update($this->files(['commercial_registration', 'safety_certification']))->assertOk();

        $this->assertSame(['pending', 'pending'], [$this->types()['commercial_registration']['status'], $this->types()['safety_certification']['status']]);
        $this->assertSame($before + 2, DB::table('provider_documents')->count(), 'The earlier versions are kept for the record');
        $this->assertSame(0, DB::table('provider_documents')->where('type', 'commercial_registration')->whereNull('superseded_at')->where('status', 'rejected')->count(), 'And superseded');
    }

    public function test_what_is_still_required_is_what_is_not_uploaded_or_needs_updating_and_not_what_waits_or_is_accepted(): void
    {
        $this->update($this->files(self::REQUIRED))->assertOk();
        $this->review()->approve($this->current('commercial_registration'), $this->admin);
        $this->review()->reject($this->current('safety_certification'), $this->admin, 'No');
        // commercial_registration is accepted, safety_certification needs updating, insurance_certificate waits.

        $r = $this->update($this->files(['business_license']))->assertOk();

        $this->assertSame(['safety_certification'], $r->json('data.required_remaining'));
        $this->assertFalse($r->json('data.all_required_provided'));
    }

    // --- All or nothing ---------------------------------------------------------------------------------------------------

    public function test_one_bad_file_an_unknown_key_or_an_accepted_document_stores_none_of_them(): void
    {
        $this->update($this->files(['commercial_registration']))->assertOk();
        $this->review()->approve($this->current('commercial_registration'), $this->admin);
        $files = $this->storedFiles();
        $rows = DB::table('provider_documents')->count();

        $bad = $this->update(['safety_certification' => $this->pdf(), 'other' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422);
        $this->assertArrayHasKey('documents.other', $bad->json('errors'));

        $unknown = $this->update(['safety_certification' => $this->pdf(), 'passport_of_the_cat' => $this->pdf()])->assertStatus(422);
        $this->assertStringContainsString('GET /provider/document-types lists them', (string) collect($unknown->json('errors'))->flatten()->first());

        $accepted = $this->update($this->files(['commercial_registration', 'safety_certification']))->assertStatus(409);
        $this->assertStringContainsString('Already accepted', $accepted->json('message'), 'In the words of this screen');
        $this->assertStringContainsString('commercial_registration', $accepted->json('message'));
        $this->assertStringContainsString('Nothing was uploaded', $accepted->json('message'));

        $this->assertSame([$files, $rows], [$this->storedFiles(), DB::table('provider_documents')->count()], 'Nothing from any of them was kept');
    }

    public function test_nothing_sent_is_refused_and_says_where_the_keys_are(): void
    {
        $r = $this->hit($this->p['token'], 'POST', self::URL, [])->assertStatus(422);

        $this->assertStringContainsString('GET /provider/document-types lists the keys', $r->json('errors.documents.0'));
        $this->assertSame(0, DB::table('provider_documents')->count());
    }

    // --- Who ---------------------------------------------------------------------------------------------------------

    public function test_a_provider_who_is_not_approved_yet_sees_and_updates_their_documents(): void
    {
        $applicant = $this->unapprovedProvider('pending');

        $this->assertSame(['not_uploaded'], collect($this->types($applicant))->pluck('status')->unique()->values()->all());
        $this->update($this->files(self::REQUIRED), 'PUT', $applicant)->assertOk();
        $this->assertSame(3, DB::table('provider_documents')->where('provider_id', $applicant['provider']->id)->count());
        $this->assertSame('pending', $this->types($applicant)['commercial_registration']['status']);
    }

    public function test_one_providers_documents_are_not_anothers(): void
    {
        $other = $this->approvedProvider();

        $this->update($this->files(self::REQUIRED), 'PUT', $other)->assertOk();

        $this->assertSame(['not_uploaded'], collect($this->types())->pluck('status')->unique()->values()->all());
        $this->assertSame(0, DB::table('provider_documents')->where('provider_id', $this->p['provider']->id)->count());
    }

    public function test_a_customer_and_nobody_may_neither_see_nor_update_them(): void
    {
        [$customer] = $this->makeCustomer();
        $token = $this->tokenFor($customer, 'customer');

        $this->hit($token, 'GET', self::URL)->assertForbidden();
        $this->hit(null, 'GET', self::URL)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->call('PUT', self::URL, [], [], ['documents' => $this->files(['commercial_registration'])], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_ACCEPT' => 'application/json'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->call('PUT', self::URL, [], [], ['documents' => $this->files(['commercial_registration'])], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();

        $this->assertSame(0, DB::table('provider_documents')->count());
    }

    public function test_the_verification_endpoints_still_work_beside_them(): void
    {
        $this->update($this->files(['commercial_registration']))->assertOk();

        $this->assertSame('pending', collect($this->hit($this->p['token'], 'GET', '/api/v1/provider/verification')->json('data'))->firstWhere('key', 'commercial_registration')['status']);
        $this->hitWithFiles($this->p['token'], '/api/v1/provider/verification/safety_certification', ['file' => $this->pdf()])->assertCreated();
        $this->assertSame('pending', $this->types()['safety_certification']['status']);
    }
}
