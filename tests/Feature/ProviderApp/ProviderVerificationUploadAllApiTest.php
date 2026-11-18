<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\User;
use App\Repositories\Contracts\ProviderDocumentRepositoryInterface;
use App\Services\NotificationDispatchService;
use App\Services\ProviderDocumentReviewService;
use App\Services\ProviderDocumentService;
use App\Services\ProviderDocumentStatuses;
use App\Services\VerificationDocumentTypes;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * POST /provider/verification: ALL the documents in one request (documents[key]=<file>), all or nothing. The three mandatory documents are commercial_registration,
 * safety_certification and insurance_certificate; the other five (business_license, identity_document, professional_certification, insurance, other) are optional.
 */
class ProviderVerificationUploadAllApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const URL = '/api/v1/provider/verification';

    private const MANDATORY = ['commercial_registration', 'safety_certification', 'insurance_certificate'];

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

    private function uploadAll(array $documents, ?array $as = null)
    {
        return $this->hitWithFiles(($as ?? $this->p)['token'], self::URL, ['documents' => $documents]);
    }

    private function list(?array $as = null): array
    {
        return collect($this->hit(($as ?? $this->p)['token'], 'GET', self::URL)->assertOk()->json('data'))->keyBy('key')->all();
    }

    private function current(string $key, ?array $as = null): ?ProviderDocument
    {
        return ($as ?? $this->p)['provider']->documents()->where('type', $key)->orderByDesc('id')->first();
    }

    private function storedFiles(): int
    {
        return count(Storage::disk(config('providers.documents.disk'))->allFiles());
    }

    private function review(): ProviderDocumentReviewService
    {
        return app(ProviderDocumentReviewService::class);
    }

    // --- What the list says to send -------------------------------------------------------------------------------------

    public function test_the_list_says_which_documents_are_required_and_which_are_optional_and_what_a_file_must_be(): void
    {
        $rows = $this->list();

        $this->assertSame(self::MANDATORY, collect($rows)->where('is_mandatory', true)->keys()->all());
        $this->assertSame(['business_license', 'identity_document', 'professional_certification', 'insurance', 'other'], collect($rows)->where('is_mandatory', false)->keys()->all());
        foreach ($rows as $row) {
            $this->assertSame(['pdf', 'jpg', 'jpeg', 'png', 'webp'], $row['accepted_formats']);
            $this->assertSame(5120, $row['max_size_kb']);
        }
    }

    // --- Sending them all ----------------------------------------------------------------------------------------------------

    public function test_all_three_mandatory_documents_in_one_request_make_the_provider_complete(): void
    {
        $r = $this->uploadAll($this->files(self::MANDATORY))->assertCreated();

        $this->assertSame(['status', 'message', 'data'], array_keys($r->json()));
        $this->assertSame('3 documents uploaded and sent for review.', $r->json('message'));
        $this->assertSame(['uploaded', 'required_remaining', 'all_required_uploaded', 'documents'], array_keys($r->json('data')));
        $this->assertSame(self::MANDATORY, array_column($r->json('data.uploaded'), 'key'), 'In the order sent');
        $this->assertSame(['pending', 'pending', 'pending'], array_column($r->json('data.uploaded'), 'status'));
        $this->assertSame(['key', 'status', 'uploaded_at'], array_keys($r->json('data.uploaded.0')));
        $this->assertSame('2026-11-15T12:00:00Z', $r->json('data.uploaded.0.uploaded_at'));
        $this->assertSame([[], true], [$r->json('data.required_remaining'), $r->json('data.all_required_uploaded')]);
        $this->assertSame(3, $this->storedFiles());
    }

    public function test_every_document_at_once_mandatory_and_optional_alike(): void
    {
        $keys = array_keys($this->list());

        $r = $this->uploadAll($this->files($keys))->assertCreated();

        $this->assertSame('8 documents uploaded and sent for review.', $r->json('message'));
        $this->assertSame($keys, array_column($r->json('data.uploaded'), 'key'));
        $this->assertSame(8, $this->storedFiles());
        foreach ($this->list() as $row) {
            $this->assertSame('pending', $row['status']);
        }
    }

    public function test_the_answer_carries_the_whole_list_as_the_get_gives_it(): void
    {
        $r = $this->uploadAll($this->files(['commercial_registration']))->assertCreated();

        $this->assertSame(array_values($this->list()), $r->json('data.documents'), 'The same rows as GET /provider/verification, one for each document');
        $this->assertSame('pending', $r->json('data.documents.0.status'));
        $this->assertSame('missing', $r->json('data.documents.1.status'));
    }

    public function test_optional_documents_may_be_left_out_and_what_is_still_required_is_listed(): void
    {
        $r = $this->uploadAll($this->files(['commercial_registration', 'business_license']))->assertCreated();

        $this->assertSame(['safety_certification', 'insurance_certificate'], $r->json('data.required_remaining'));
        $this->assertFalse($r->json('data.all_required_uploaded'));
        $this->assertSame('missing', $this->list()['identity_document']['status'], 'A document not sent is left as it was');
    }

    public function test_one_document_alone_is_a_complete_request_with_its_own_message(): void
    {
        $r = $this->uploadAll($this->files(['insurance_certificate']))->assertCreated();

        $this->assertSame('Document uploaded and sent for review.', $r->json('message'));
        $this->assertSame(['commercial_registration', 'safety_certification'], $r->json('data.required_remaining'));
    }

    public function test_the_files_are_kept_privately_and_reachable_only_through_the_expiring_link(): void
    {
        $this->uploadAll($this->files(['commercial_registration']))->assertCreated();
        $row = $this->list()['commercial_registration'];

        $this->assertStringContainsString('signature=', $row['file_url']);
        $this->assertSame('pending', $row['status']);
        $this->assertStringNotContainsString('provider-documents', json_encode($row), 'The storage path is never given out');
    }

    // --- Replacing -------------------------------------------------------------------------------------------------------

    public function test_a_rejected_an_update_requested_and_a_waiting_document_are_all_replaced_in_one_go(): void
    {
        $this->uploadAll($this->files(self::MANDATORY))->assertCreated();
        $this->review()->reject($this->current('commercial_registration'), $this->admin, 'The scan is not clear');
        $this->review()->approve($this->current('safety_certification'), $this->admin);
        $this->review()->requestUpdate($this->p['provider'], 'safety_certification', $this->admin, 'The business name changed');
        $oldIds = DB::table('provider_documents')->pluck('id')->all();

        $r = $this->uploadAll($this->files(self::MANDATORY))->assertCreated();   // insurance_certificate is still waiting: replaced too

        $this->assertSame(['pending', 'pending', 'pending'], array_column($r->json('data.uploaded'), 'status'));
        $this->assertSame(0, DB::table('provider_documents')->whereIn('id', $oldIds)->whereNull('superseded_at')->count(), 'The earlier versions are kept, and superseded');
        $this->assertSame(6, DB::table('provider_documents')->count(), 'Kept for the record, not deleted');
        foreach (self::MANDATORY as $key) {
            $this->assertSame('pending', $this->list()[$key]['status'], $key);
        }
    }

    // --- All or nothing ---------------------------------------------------------------------------------------------------

    public function test_one_bad_file_stores_none_of_them(): void
    {
        $before = $this->storedFiles();

        foreach ([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')] as $bad) {
            $r = $this->uploadAll(['commercial_registration' => $this->pdf(), 'safety_certification' => $bad, 'insurance_certificate' => $this->pdf()])->assertStatus(422);

            $this->assertFalse($r->json('status'));
            $this->assertArrayHasKey('documents.safety_certification', $r->json('errors'));
        }

        $this->assertSame($before, $this->storedFiles());
        $this->assertSame(0, DB::table('provider_documents')->count(), 'Not even the good ones were kept');
    }

    public function test_a_key_that_is_not_a_document_stores_none_of_them(): void
    {
        $r = $this->uploadAll(['commercial_registration' => $this->pdf(), 'passport_of_the_cat' => $this->pdf()])->assertStatus(422);

        $this->assertArrayHasKey('documents.passport_of_the_cat', $r->json('errors'));
        $this->assertSame(0, DB::table('provider_documents')->count());
        $this->assertSame(0, $this->storedFiles());
    }

    public function test_an_approved_document_refuses_the_whole_request_and_says_which(): void
    {
        $this->uploadAll($this->files(['commercial_registration']))->assertCreated();
        $this->review()->approve($this->current('commercial_registration'), $this->admin);
        $filesBefore = $this->storedFiles();
        $rowsBefore = DB::table('provider_documents')->count();

        $r = $this->uploadAll($this->files(['commercial_registration', 'safety_certification', 'insurance_certificate']))->assertStatus(409);

        $this->assertFalse($r->json('status'));
        $this->assertStringContainsString('commercial_registration', $r->json('message'));
        $this->assertStringContainsString('Nothing was uploaded', $r->json('message'));
        $this->assertSame([$filesBefore, $rowsBefore], [$this->storedFiles(), DB::table('provider_documents')->count()], 'The two that were fine were not kept either');
        $this->assertSame('approved', $this->list()['commercial_registration']['status']);
    }

    public function test_a_failure_part_way_leaves_no_row_and_no_file(): void
    {
        $this->app->extend(ProviderDocumentService::class, fn (ProviderDocumentService $real, $app) => new class($app->make(ProviderDocumentRepositoryInterface::class), $app->make(NotificationDispatchService::class), $app->make(VerificationDocumentTypes::class), $app->make(ProviderDocumentStatuses::class)) extends ProviderDocumentService
        {
            private int $calls = 0;

            public function replace(Provider $provider, string $type, UploadedFile $file, bool $notify = true): ProviderDocument
            {
                if (++$this->calls === 3) {
                    throw new \RuntimeException('the disk went away');
                }

                return parent::replace($provider, $type, $file, $notify);
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->uploadAll($this->files(self::MANDATORY));
            $this->fail('The failure should have been thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('the disk went away', $e->getMessage());
        }

        $this->assertSame(0, DB::table('provider_documents')->count(), 'The two that were made were rolled back');
        $this->assertSame(0, $this->storedFiles(), 'And the files that were stored were taken off the disk');
        $this->assertSame(0, DB::table('notifications')->count(), 'Nobody was told');
    }

    // --- What is refused as a request -------------------------------------------------------------------------------------

    public function test_nothing_sent_or_something_that_is_not_a_file_is_refused(): void
    {
        $this->hit($this->p['token'], 'POST', self::URL, [])->assertStatus(422)->assertJsonValidationErrors('documents');
        $this->hit($this->p['token'], 'POST', self::URL, ['documents' => []])->assertStatus(422)->assertJsonValidationErrors('documents');
        $this->hit($this->p['token'], 'POST', self::URL, ['documents' => ['commercial_registration' => 'not a file']])->assertStatus(422)->assertJsonValidationErrors('documents.commercial_registration');
        $this->hit($this->p['token'], 'POST', self::URL, ['documents' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('documents');

        $this->assertSame(0, DB::table('provider_documents')->count());
    }

    // --- The people who review them ---------------------------------------------------------------------------------------

    public function test_the_reviewers_are_told_once_for_each_document_after_all_are_saved(): void
    {
        $this->uploadAll($this->files(self::MANDATORY))->assertCreated();

        $told = DB::table('notifications')->where('notifiable_id', $this->admin->id)->count();

        $this->assertSame(3, $told, 'One for each document');
    }

    // --- Who ---------------------------------------------------------------------------------------------------------

    public function test_a_provider_who_is_not_approved_yet_can_send_their_documents(): void
    {
        $applicant = $this->unapprovedProvider('pending');

        $this->uploadAll($this->files(self::MANDATORY), $applicant)->assertCreated();

        $this->assertSame(3, DB::table('provider_documents')->where('provider_id', $applicant['provider']->id)->count());
    }

    public function test_one_providers_documents_are_not_anothers(): void
    {
        $other = $this->approvedProvider();

        $this->uploadAll($this->files(self::MANDATORY), $other)->assertCreated();

        $this->assertSame(0, DB::table('provider_documents')->where('provider_id', $this->p['provider']->id)->count());
        $this->assertSame('missing', $this->list()['commercial_registration']['status']);
    }

    public function test_a_customer_and_nobody_may_not_send_documents(): void
    {
        [$customer] = $this->makeCustomer();

        $this->hitWithFiles($this->tokenFor($customer, 'customer'), self::URL, ['documents' => $this->files(['commercial_registration'])])->assertForbidden();
        $this->hitWithFiles(null, self::URL, ['documents' => $this->files(['commercial_registration'])])->assertUnauthorized();

        $this->assertSame(0, DB::table('provider_documents')->count());
    }

    public function test_the_single_upload_still_works_beside_it(): void
    {
        $this->hitWithFiles($this->p['token'], self::URL.'/commercial_registration', ['file' => $this->pdf()])->assertCreated();

        $this->assertSame('pending', $this->list()['commercial_registration']['status']);
    }
}
