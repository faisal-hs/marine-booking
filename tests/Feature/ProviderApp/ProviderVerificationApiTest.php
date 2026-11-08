<?php

namespace Tests\Feature\ProviderApp;

use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\User;
use App\Models\VerificationDocumentType;
use App\Services\ProviderDocumentReviewService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/verification and POST /provider/verification/{key}. A provider sees every document they can be asked for, where each stands, and
 * uploads or replaces one. Uploading and an admin's approval are separate steps: an upload is `pending` until an admin decides.
 */
class ProviderVerificationApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

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

    private function list(?array $as = null)
    {
        return $this->hit(($as ?? $this->p)['token'], 'GET', '/api/v1/provider/verification');
    }

    private function row(string $key, ?array $as = null): array
    {
        return collect($this->list($as)->assertOk()->json('data'))->firstWhere('key', $key);
    }

    private function upload(string $key, ?UploadedFile $file = null, ?array $as = null)
    {
        return $this->hitWithFiles(($as ?? $this->p)['token'], "/api/v1/provider/verification/{$key}", ['file' => $file ?? UploadedFile::fake()->create("{$key}.pdf", 40, 'application/pdf')]);
    }

    private function current(string $key, ?array $as = null): ?ProviderDocument
    {
        return ($as ?? $this->p)['provider']->documents()->where('type', $key)->first();
    }

    // --- The list --------------------------------------------------------------------------------------------------

    public function test_a_new_provider_sees_every_document_as_missing(): void
    {
        $data = $this->list()->assertOk()->json('data');

        $this->assertSame(['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license', 'identity_document', 'professional_certification', 'insurance', 'other'], array_column($data, 'key'));
        foreach ($data as $row) {
            $this->assertSame(['key', 'title', 'status', 'is_done', 'file_url', 'review_note', 'description', 'is_mandatory', 'uploaded_at', 'expires_at', 'update_requested_at', 'can_upload'], array_keys($row));
            $this->assertSame(['missing', false, null, null, true], [$row['status'], $row['is_done'], $row['file_url'], $row['review_note'], $row['can_upload']]);
        }
        $this->assertSame(['commercial_registration', 'safety_certification', 'insurance_certificate'], collect($data)->where('is_mandatory', true)->pluck('key')->all());
        $this->assertSame('Commercial registration', $data[0]['title']);
    }

    public function test_the_list_follows_what_the_admin_configured(): void
    {
        VerificationDocumentType::where('key', 'other')->update(['is_active' => false]);
        VerificationDocumentType::create(['key' => 'boat_registration', 'title_en' => 'Boat registration', 'title_ar' => 'تسجيل القارب', 'description_en' => 'The registration of the boat', 'is_mandatory' => true, 'sort_order' => 99]);

        $data = $this->list()->json('data');

        $this->assertNotContains('other', array_column($data, 'key'), 'A switched-off type is not asked for');
        $boat = collect($data)->firstWhere('key', 'boat_registration');
        $this->assertSame(['Boat registration', 'The registration of the boat', true], [$boat['title'], $boat['description'], $boat['is_mandatory']]);
        $this->assertSame('boat_registration', end($data)['key'], 'In the admin\'s order');
    }

    public function test_the_title_follows_the_language_of_the_request(): void
    {
        $type = VerificationDocumentType::where('key', 'insurance')->first();

        app()->setLocale('ar');
        $this->assertSame($type->title_ar, $this->row('insurance')['title']);

        app()->setLocale('en');
        $this->assertSame($type->title_en, $this->row('insurance')['title']);
    }

    // --- Uploading -------------------------------------------------------------------------------------------------

    public function test_an_upload_is_sent_for_review_and_the_answer_has_the_specs_shape(): void
    {
        $response = $this->upload('safety_certification')->assertCreated();

        $this->assertSame(['status' => true, 'message' => 'Document uploaded and sent for review'], array_intersect_key($response->json(), ['status' => 1, 'message' => 1]));
        $this->assertSame(['key' => 'safety_certification', 'status' => 'pending', 'uploaded_at' => '2026-11-15T12:00:00Z'], $response->json('data'));

        $document = $this->current('safety_certification');
        $this->assertSame(['pending', null, null], [$document->status, $document->reviewed_at, $document->review_note]);
        $this->assertSame(['submitted'], ProviderDocumentEvent::where('provider_id', $this->p['provider']->id)->pluck('action')->all(), 'The history starts with the upload');

        $row = $this->row('safety_certification');
        $this->assertSame(['pending', false, true], [$row['status'], $row['is_done'], $row['can_upload']]);
        $this->assertSame('2026-11-15T12:00:00Z', $row['uploaded_at']);
    }

    public function test_the_file_is_stored_privately_under_a_generated_name(): void
    {
        $this->upload('safety_certification', UploadedFile::fake()->create('../../evil name.pdf', 40, 'application/pdf'))->assertCreated();

        $document = $this->current('safety_certification');
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsWith("provider-documents/{$this->p['provider']->uuid}/", $document->path);
        $this->assertStringNotContainsString('evil', $document->path);
        $this->assertStringNotContainsString('/', $document->original_name, 'The name shown never carries a path');
    }

    public function test_uploading_again_while_it_waits_replaces_it_and_keeps_the_old_version(): void
    {
        $this->upload('safety_certification')->assertCreated();
        $first = $this->current('safety_certification');

        $this->upload('safety_certification', UploadedFile::fake()->create('better.pdf', 60, 'application/pdf'))->assertCreated();

        $second = $this->current('safety_certification');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('better.pdf', $second->original_name);
        $this->assertNotNull($first->refresh()->superseded_at, 'The earlier version is kept, marked as replaced');
        Storage::disk('local')->assertExists($first->path);
        $this->assertSame(1, $this->p['provider']->documents()->where('type', 'safety_certification')->count(), 'One in force');
        $this->assertCount(1, array_filter($this->list()->json('data'), fn ($r) => $r['key'] === 'safety_certification' && $r['status'] === 'pending'));
    }

    public function test_a_rejected_document_can_be_replaced_and_the_reason_is_shown_until_then(): void
    {
        $this->upload('safety_certification');
        app(ProviderDocumentReviewService::class)->reject($this->current('safety_certification'), $this->admin, 'The document has expired');

        $row = $this->row('safety_certification');
        $this->assertSame(['rejected', false, 'The document has expired', true], [$row['status'], $row['is_done'], $row['review_note'], $row['can_upload']]);

        $this->upload('safety_certification')->assertCreated();

        $row = $this->row('safety_certification');
        $this->assertSame(['pending', null], [$row['status'], $row['review_note']], 'The reason belonged to the previous version');
    }

    public function test_an_approved_document_stays_unless_an_admin_asks_for_an_update(): void
    {
        $this->upload('safety_certification');
        app(ProviderDocumentReviewService::class)->approve($this->current('safety_certification'), $this->admin);
        $before = $this->current('safety_certification');

        $row = $this->row('safety_certification');
        $this->assertSame(['approved', true, false], [$row['status'], $row['is_done'], $row['can_upload']]);

        $this->upload('safety_certification')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $this->assertSame($before->id, $this->current('safety_certification')->id, 'Nothing was stored');
        $this->assertSame(1, ProviderDocument::where('type', 'safety_certification')->count());
        $this->assertNull($before->refresh()->superseded_at);
    }

    public function test_an_update_the_admin_asked_for_can_be_uploaded_and_that_closes_the_request(): void
    {
        $this->upload('safety_certification');
        $review = app(ProviderDocumentReviewService::class);
        $review->approve($this->current('safety_certification'), $this->admin);
        $review->requestUpdate($this->p['provider'], 'safety_certification', $this->admin, 'The business name changed: send an updated one');

        $row = $this->row('safety_certification');
        $this->assertSame(['update_requested', false, 'The business name changed: send an updated one', true], [$row['status'], $row['is_done'], $row['review_note'], $row['can_upload']]);
        $this->assertSame('2026-11-15T12:00:00Z', $row['update_requested_at']);
        $this->assertNotNull($row['file_url'], 'The version in force is still there to read');

        $this->upload('safety_certification')->assertCreated();

        $row = $this->row('safety_certification');
        $this->assertSame(['pending', null, null], [$row['status'], $row['review_note'], $row['update_requested_at']]);
    }

    public function test_a_document_can_be_asked_for_before_anything_was_uploaded(): void
    {
        app(ProviderDocumentReviewService::class)->requestUpdate($this->p['provider'], 'business_license', $this->admin, 'Please send your trade licence');

        $row = $this->row('business_license');
        $this->assertSame(['update_requested', 'Please send your trade licence', null, true], [$row['status'], $row['review_note'], $row['file_url'], $row['can_upload']]);

        $this->upload('business_license')->assertCreated();
        $this->assertSame('pending', $this->row('business_license')['status']);
    }

    public function test_an_expired_document_can_be_replaced(): void
    {
        $this->upload('insurance_certificate');
        app(ProviderDocumentReviewService::class)->approve($this->current('insurance_certificate'), $this->admin, Carbon::parse('2026-11-20'));
        $this->assertSame('approved', $this->row('insurance_certificate')['status']);

        Carbon::setTestNow('2026-11-21 08:00:00');
        $row = $this->row('insurance_certificate');
        $this->assertSame(['expired', false, true, '2026-11-20'], [$row['status'], $row['is_done'], $row['can_upload'], $row['expires_at']]);

        $this->upload('insurance_certificate')->assertCreated();
        $this->assertSame('pending', $this->row('insurance_certificate')['status']);
    }

    // --- Types ---------------------------------------------------------------------------------------------------------

    public function test_an_unknown_or_switched_off_type_is_not_found(): void
    {
        VerificationDocumentType::where('key', 'insurance')->update(['is_active' => false]);

        $this->upload('no_such_document')->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->upload('insurance')->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        $this->assertSame(0, ProviderDocument::count());
    }

    // --- The file ---------------------------------------------------------------------------------------------------

    public function test_the_file_url_is_an_expiring_signed_link_that_needs_no_token(): void
    {
        $this->upload('safety_certification', UploadedFile::fake()->createWithContent('safety.pdf', '%PDF-1.4 my safety certificate'))->assertCreated();
        $url = $this->row('safety_certification')['file_url'];
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $file = $this->get($url)->assertOk();

        $this->assertStringContainsString('my safety certificate', $file->streamedContent());
        $this->assertSame('nosniff', $file->headers->get('x-content-type-options'));
        $this->assertStringContainsString('attachment', $file->headers->get('content-disposition'));
    }

    public function test_a_link_cannot_be_altered_pointed_elsewhere_or_used_after_it_expires(): void
    {
        $this->upload('safety_certification');
        $this->upload('insurance_certificate');
        $url = $this->row('safety_certification')['file_url'];
        $other = $this->current('insurance_certificate')->uuid;
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->get(preg_replace('#/files/[0-9a-f-]+#', "/files/{$other}", $url))->assertForbidden();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
        $this->getJson("/api/v1/provider/verification/files/{$this->current('safety_certification')->uuid}")->assertForbidden();
        $this->getJson('/api/v1/provider/verification/files/'.Str::uuid())->assertForbidden();   // an id that does not exist is refused the same way

        Carbon::setTestNow(now()->addMinutes((int) config('wallet.statement_link_minutes') + 1));
        $this->get($url)->assertForbidden();
    }

    public function test_the_list_never_offers_a_link_to_a_replaced_version(): void
    {
        $this->upload('safety_certification');
        $old = $this->current('safety_certification');
        $this->upload('safety_certification');

        $this->assertStringNotContainsString($old->uuid, $this->row('safety_certification')['file_url']);
        $this->assertStringContainsString($this->current('safety_certification')->uuid, $this->row('safety_certification')['file_url']);
    }

    // --- Validation -----------------------------------------------------------------------------------------------

    public function test_a_bad_file_is_refused_and_nothing_is_stored(): void
    {
        $bad = [
            'nothing' => null,
            'a program' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            'a web page' => UploadedFile::fake()->create('page.html', 10, 'text/html'),
            'an svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'too big' => UploadedFile::fake()->create('big.pdf', config('providers.documents.max_kb') + 1, 'application/pdf'),
        ];

        foreach ($bad as $why => $file) {
            $response = $file === null
                ? $this->hit($this->p['token'], 'POST', '/api/v1/provider/verification/safety_certification', [])
                : $this->upload('safety_certification', $file);

            $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('file');
            $this->assertSame(0, ProviderDocument::count(), "Refused: {$why}");
        }
    }

    public function test_pdf_and_the_raster_image_types_are_accepted(): void
    {
        foreach (['safety_certification' => ['a.pdf', 'application/pdf'], 'insurance_certificate' => ['b.png', 'image/png'], 'business_license' => ['c.jpg', 'image/jpeg']] as $key => [$name, $mime]) {
            $this->upload($key, UploadedFile::fake()->create($name, 20, $mime))->assertCreated();
        }

        $this->assertSame(3, ProviderDocument::count());
    }

    public function test_a_provider_cannot_keep_more_documents_than_the_limit(): void
    {
        config(['providers.documents.max_per_provider' => 2]);

        $this->upload('safety_certification')->assertCreated();
        $this->upload('insurance_certificate')->assertCreated();
        $this->upload('business_license')->assertStatus(422)->assertJsonValidationErrors('file');

        $this->upload('insurance_certificate')->assertCreated();   // replacing never needs more room
        $this->assertSame(2, $this->p['provider']->documents()->count());
    }

    // --- Who may ---------------------------------------------------------------------------------------------------

    public function test_a_provider_who_is_not_approved_yet_can_use_it_because_it_is_part_of_getting_approved(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $unapproved = $this->unapprovedProvider($status);

            $this->list($unapproved)->assertOk();
            $this->upload('commercial_registration', null, $unapproved)->assertCreated();
            $this->assertSame('pending', $this->row('commercial_registration', $unapproved)['status']);
        }
    }

    public function test_each_provider_sees_only_their_own_documents(): void
    {
        $other = $this->approvedProvider();
        $this->upload('safety_certification');

        $this->assertSame('missing', $this->row('safety_certification', $other)['status']);
        $this->assertSame('pending', $this->row('safety_certification')['status']);
        $this->assertSame(0, $other['provider']->documents()->count());
    }

    public function test_a_customer_and_nobody_are_refused(): void
    {
        [$customer] = $this->makeCustomer();

        $this->hit($this->tokenFor($customer, 'customer'), 'GET', '/api/v1/provider/verification')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->hit(null, 'GET', '/api/v1/provider/verification')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->hitWithFiles(null, '/api/v1/provider/verification/safety_certification', ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')])->assertUnauthorized();
        $this->assertSame(0, ProviderDocument::count());
    }

    // --- The rate limit -------------------------------------------------------------------------------------------

    public function test_uploads_are_limited_to_twenty_a_minute_with_a_budget_of_their_own(): void
    {
        foreach (range(1, 20) as $n) {
            $this->hit($this->p['token'], 'POST', '/api/v1/provider/verification/safety_certification', [])->assertStatus(422);
        }
        $this->hit($this->p['token'], 'POST', '/api/v1/provider/verification/safety_certification', [])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $this->hit($this->p['token'], 'PUT', '/api/v1/provider/bank-account', ['iban' => 'nope'])->assertStatus(422);   // other budgets are untouched
        $this->list()->assertOk();
    }
}
