<?php

namespace Tests\Feature\Account;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderRequest;
use App\Notifications\Providers\Admin\ProviderRequestSubmittedAdminNotification;
use App\Services\ProviderRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Asking to become a provider: a customer who wants to start offering activities, or someone who
 * registered as a provider, submits business details and three documents and waits for an admin.
 */
class ProviderRequestApiTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const REQUIRED = ['commercial_registration', 'safety_certification', 'insurance_certificate'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function submit(string $token, array $overrides = [])
    {
        return $this->asAccount($token)->post('/api/v1/account/provider-request', $this->providerRequestPayload($overrides), ['Accept' => 'application/json']);
    }

    private function requestStatus(string $token)
    {
        return $this->asAccount($token)->getJson('/api/v1/account/provider-request');
    }

    private function decide(ProviderRequest $request, string $decision, string $reason = 'The registration is unreadable.'): void
    {
        $admin = $this->makeAdmin('admin');
        $service = app(ProviderRequestService::class);
        $decision === 'approve' ? $service->approve($request, $admin) : $service->reject($request, $admin, $reason);
    }

    // --- Submitting --------------------------------------------------------------------

    public function test_a_customer_can_ask_to_become_a_provider(): void
    {
        [$user] = $this->makeCustomer();

        $response = $this->submit($this->tokenFor($user, 'customer'))->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.request.status', 'pending')
            ->assertJsonPath('data.request.source', 'customer_upgrade')
            ->assertJsonPath('data.provider.business_name', 'Jeddah Divers')
            ->assertJsonPath('data.provider.city', 'Jeddah')
            ->assertJsonPath('data.provider.area', 'North Obhur')
            ->assertJsonPath('data.documents.commercial_registration.original_name', 'cr.pdf')
            ->assertJsonPath('data.documents.safety_certification.original_name', 'safety.pdf')
            ->assertJsonPath('data.documents.insurance_certificate.original_name', 'insurance.pdf');

        $provider = $user->fresh()->provider;
        $this->assertSame('pending', $provider->verification_status);
        $this->assertSame('jeddah-divers', $provider->slug);
        $this->assertEqualsCanonicalizing(self::REQUIRED, $provider->documents->pluck('type')->all());
        $this->assertSame('customer', $user->fresh()->user_type, 'They are still a customer, and stay one.');
        $this->assertSame(1, ProviderRequest::count());
    }

    public function test_the_documents_are_kept_privately_never_on_the_public_disk(): void
    {
        [$user] = $this->makeCustomer();

        $this->submit($this->tokenFor($user, 'customer'))->assertCreated();

        $this->assertCount(3, Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        foreach (ProviderDocument::all() as $document) {
            $this->assertStringStartsWith('provider-documents/' . $user->fresh()->provider->uuid, $document->path);
            Storage::disk('local')->assertExists($document->path);
        }
    }

    public function test_someone_who_registered_as_a_provider_submits_the_same_request(): void
    {
        [$user, $provider, $token] = $this->makeProvider('pending');
        $provider->update(['business_name' => 'Jeddah Divers', 'slug' => 'jeddah-divers']);

        $this->submit($token)->assertCreated()->assertJsonPath('data.request.source', 'registration');

        $provider->refresh();
        $this->assertSame('jeddah-divers', $provider->slug, 'The same business name keeps its address.');
        $this->assertSame('Jeddah', $provider->city);
        $this->assertSame(1, Provider::where('user_id', $user->id)->count(), 'The existing provider account is filled in, not duplicated.');
    }

    public function test_a_new_business_name_gives_an_unapproved_provider_a_new_address(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $provider->update(['business_name' => 'Old Name', 'slug' => 'old-name']);

        $this->submit($token, ['business_name' => 'Blue Hole Divers'])->assertCreated();

        $this->assertSame('blue-hole-divers', $provider->fresh()->slug);
    }

    public function test_the_address_of_a_deleted_business_is_not_reused(): void
    {
        [, $other] = $this->makeProvider('verified');
        $other->update(['slug' => 'jeddah-divers']);
        $other->delete();
        [$user] = $this->makeCustomer();

        $this->submit($this->tokenFor($user, 'customer'))->assertCreated();

        $this->assertSame('jeddah-divers-1', $user->fresh()->provider->slug);
    }

    public function test_bio_and_address_are_optional_and_kept_when_given(): void
    {
        [$user] = $this->makeCustomer();

        $this->submit($this->tokenFor($user, 'customer'), ['bio' => 'Family run since 2009.', 'address' => 'Corniche Road'])->assertCreated()
            ->assertJsonPath('data.provider.bio', 'Family run since 2009.')
            ->assertJsonPath('data.provider.address', 'Corniche Road');
    }

    // --- Validation --------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function requiredFields(): array
    {
        return array_combine(
            ['business name', 'city', 'area', 'commercial registration', 'safety certification', 'insurance certificate'],
            array_map(fn($f) => [$f], ['business_name', 'city', 'area', 'commercial_registration', 'safety_certification', 'insurance_certificate'])
        );
    }

    #[DataProvider('requiredFields')]
    public function test_every_field_and_every_document_is_required(string $field): void
    {
        [$user] = $this->makeCustomer();
        $payload = $this->providerRequestPayload();
        unset($payload[$field]);

        $this->asAccount($this->tokenFor($user, 'customer'))->post('/api/v1/account/provider-request', $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertNull($user->fresh()->provider, 'A rejected submission must not leave a provider account behind.');
        $this->assertSame(0, ProviderRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nor any file.');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [filename, content, field] */
    public static function rejectedFiles(): array
    {
        return [
            'an SVG, which can carry a script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'commercial_registration'],
            'an HTML page' => ['page.html', '<script>alert(1)</script>', 'safety_certification'],
            'a PHP file' => ['shell.php', '<?php system($_GET["c"]);', 'insurance_certificate'],
            'plain text' => ['notes.txt', 'hello', 'commercial_registration'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_only_pdf_and_image_documents_are_accepted(string $name, string $content, string $field): void
    {
        [$user] = $this->makeCustomer();

        $this->submit($this->tokenFor($user, 'customer'), [$field => UploadedFile::fake()->createWithContent($name, $content)])
            ->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($user->fresh()->provider);
    }

    public function test_a_document_over_the_size_limit_is_refused(): void
    {
        [$user] = $this->makeCustomer();

        $this->submit($this->tokenFor($user, 'customer'), ['insurance_certificate' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('insurance_certificate');
    }

    public function test_images_are_accepted_as_documents(): void
    {
        [$user] = $this->makeCustomer();

        $this->submit($this->tokenFor($user, 'customer'), ['commercial_registration' => UploadedFile::fake()->image('cr.jpg')])->assertCreated();
    }

    public function test_a_failure_part_way_leaves_no_files_and_no_provider_behind(): void
    {
        // Past the form validation: the service itself stores two documents, finds the third missing, and
        // must undo everything, including the files already written.
        [$user] = $this->makeCustomer();

        try {
            app(ProviderRequestService::class)->submit($user, ['business_name' => 'Half Done', 'city' => 'Jeddah', 'area' => 'Obhur'], [
                'commercial_registration' => UploadedFile::fake()->create('cr.pdf', 10, 'application/pdf'),
                'safety_certification' => UploadedFile::fake()->create('safety.pdf', 10, 'application/pdf'),
            ]);
            $this->fail('The missing document should have stopped the request.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('insurance_certificate', $e->errors());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($user->fresh()->provider);
        $this->assertSame(0, ProviderDocument::count());
    }

    // --- One at a time ------------------------------------------------------------------

    public function test_a_second_request_while_one_is_waiting_is_refused(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $this->submit($token)->assertCreated();

        $this->submit($token, ['business_name' => 'Changed My Mind'])->assertStatus(422)->assertJsonValidationErrors('provider');

        $this->assertSame(1, ProviderRequest::count());
        $this->assertSame('Jeddah Divers', $user->fresh()->provider->business_name, 'The waiting request cannot be edited either.');
    }

    public function test_an_approved_provider_cannot_ask_again(): void
    {
        [$user] = $this->makeDualAccount('verified');

        $this->submit($this->tokenFor($user, 'customer'))->assertStatus(422)->assertJsonValidationErrors('provider');
        $this->assertSame(0, ProviderRequest::count());
    }

    // --- After a rejection ----------------------------------------------------------------

    public function test_a_rejected_request_can_be_corrected_and_sent_again_keeping_the_documents_already_on_file(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $this->submit($token)->assertCreated();
        $this->decide(ProviderRequest::firstOrFail(), 'reject', 'The registration is unreadable.');
        $this->requestStatus($token)->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.request.rejection_reason', 'The registration is unreadable.');

        // Only the document that was wrong is sent again.
        $payload = $this->providerRequestPayload(['commercial_registration' => UploadedFile::fake()->create('cr-clear.pdf', 80, 'application/pdf')]);
        unset($payload['safety_certification'], $payload['insurance_certificate']);
        $this->asAccount($token)->post('/api/v1/account/provider-request', $payload, ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.documents.commercial_registration.original_name', 'cr-clear.pdf')
            ->assertJsonPath('data.documents.safety_certification.original_name', 'safety.pdf');

        $provider = $user->fresh()->provider;
        $this->assertSame('pending', $provider->verification_status);
        $this->assertNull($provider->rejection_reason, 'The old reason is cleared.');
        $this->assertSame(2, ProviderRequest::count(), 'The rejected request stays on record.');
        $this->assertSame(['rejected', 'pending'], ProviderRequest::orderBy('id')->pluck('status')->all());
    }

    public function test_replacing_a_document_keeps_the_old_version_as_history_and_lists_only_the_new_one(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $this->submit($token)->assertCreated();
        $old = ProviderDocument::where('type', 'commercial_registration')->firstOrFail();
        $this->decide(ProviderRequest::firstOrFail(), 'reject');

        $this->submit($token)->assertCreated();

        // The version an earlier decision was based on is KEPT (marked as replaced), so the record survives; only one of each is in force.
        $this->assertSame(6, ProviderDocument::count());
        $this->assertSame(3, ProviderDocument::current()->count(), 'One of each in force, not six.');
        $this->assertNotNull($old->refresh()->superseded_at);
        Storage::disk('local')->assertExists($old->path);
        $this->assertCount(6, Storage::disk('local')->allFiles());
        $this->assertSame(3, \App\Models\Provider::firstOrFail()->documents()->count(), 'The provider\'s own list shows only what is in force.');
    }

    public function test_replacing_documents_works_even_at_the_document_limit(): void
    {
        config(['providers.documents.max_per_provider' => 3]);
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $this->submit($token)->assertCreated();
        $this->decide(ProviderRequest::firstOrFail(), 'reject');

        $this->submit($token)->assertCreated();

        $this->assertSame(3, ProviderDocument::current()->count());
    }

    // --- Telling the admins --------------------------------------------------------------------

    public function test_the_admins_who_may_review_are_told_and_nobody_else(): void
    {
        $admin = $this->makeAdmin('admin');
        $super = $this->makeAdmin('super_admin');
        $support = $this->makeAdmin('support_admin');
        $content = $this->makeAdmin('content_admin');
        [$user] = $this->makeCustomer(['name' => 'Layla Hassan']);

        $this->submit($this->tokenFor($user, 'customer'))->assertCreated();

        $request = ProviderRequest::firstOrFail();
        foreach ([$admin, $super] as $reviewer) {
            $note = DB::table('notifications')->where('notifiable_id', $reviewer->id)->where('type', ProviderRequestSubmittedAdminNotification::class)->first();
            $this->assertNotNull($note, 'A reviewer must be told.');
            $data = json_decode($note->data, true);
            $this->assertSame($request->uuid, $data['provider_request_uuid']);
            $this->assertStringContainsString('Layla Hassan', $data['message']);
        }
        foreach ([$support, $content] as $outsider) {
            $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $outsider->id)->where('type', ProviderRequestSubmittedAdminNotification::class)->count());
        }
    }

    // --- Where do I stand? ----------------------------------------------------------------------

    public function test_the_status_follows_a_person_through_every_stage(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');

        $this->requestStatus($token)->assertOk()->assertJsonPath('data.status', 'none')->assertJsonPath('data.request', null)->assertJsonPath('data.provider', null)
            ->assertJsonPath('data.required_documents', self::REQUIRED);

        $this->submit($token)->assertCreated();
        $this->requestStatus($token)->assertJsonPath('data.status', 'pending');

        $this->decide(ProviderRequest::firstOrFail(), 'reject', 'Please upload a clearer registration.');
        $this->requestStatus($token)->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.request.rejection_reason', 'Please upload a clearer registration.');

        $this->submit($token)->assertCreated();
        $this->decide(ProviderRequest::latest('id')->firstOrFail(), 'approve');
        $this->requestStatus($token)->assertJsonPath('data.status', 'approved')->assertJsonPath('data.request.reviewed_at', fn($v) => $v !== null);
    }

    public function test_a_registered_provider_who_has_not_submitted_is_told_so(): void
    {
        [,, $token] = $this->makeProvider('pending');

        $this->requestStatus($token)->assertOk()->assertJsonPath('data.status', 'not_submitted')->assertJsonPath('data.request', null);
    }

    public function test_the_profile_shows_the_details_and_the_documents_even_before_approval(): void
    {
        [$user, $provider, $token] = $this->makeProvider('pending');
        $this->submit($token)->assertCreated();

        $this->asAccount($token)->getJson('/api/v1/provider/profile')->assertOk()
            ->assertJsonPath('data.city', 'Jeddah')
            ->assertJsonPath('data.area', 'North Obhur')
            ->assertJsonPath('data.commercial_registration.original_name', 'cr.pdf')
            ->assertJsonPath('data.safety_certification.original_name', 'safety.pdf')
            ->assertJsonPath('data.insurance_certificate.original_name', 'insurance.pdf')
            ->assertJsonPath('data.verification_status', 'pending');
    }

    public function test_the_status_is_always_the_callers_own(): void
    {
        [$one] = $this->makeCustomer();
        [$two] = $this->makeCustomer();
        $this->submit($this->tokenFor($one, 'customer'))->assertCreated();

        $this->requestStatus($this->tokenFor($two, 'customer'))->assertJsonPath('data.status', 'none')->assertJsonPath('data.provider', null);
    }

    public function test_it_needs_a_signed_in_person(): void
    {
        $this->getJson('/api/v1/account/provider-request')->assertUnauthorized();
        $this->postJson('/api/v1/account/provider-request', [])->assertUnauthorized();
    }

    // --- Until it is approved, nothing else ----------------------------------------------------

    public function test_before_approval_a_provider_can_submit_and_check_the_request_and_nothing_else(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $category = $this->makeCategory();

        $this->submit($token)->assertCreated();
        $this->requestStatus($token)->assertOk();
        $this->asAccount($token)->getJson('/api/v1/provider/profile')->assertOk();

        foreach (
            [
                ['getJson', '/api/v1/provider/activities'],
                ['getJson', '/api/v1/provider/reservations'],
                ['getJson', '/api/v1/provider/documents'],
                ['getJson', '/api/v1/provider/notifications'],
                ['putJson', '/api/v1/provider/profile'],
            ] as [$method, $url]
        ) {
            $this->asAccount($token)->{$method}($url)->assertForbidden()->assertJsonPath('data.provider_request_status', 'pending');
        }
        $this->asAccount($token)->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'Too early'])->assertForbidden();
        $this->assertSame(0, $provider->activities()->count());
    }

    public function test_the_same_holds_for_a_customer_who_has_a_waiting_request(): void
    {
        [$user] = $this->makeCustomer();
        $token = $this->tokenFor($user, 'customer');
        $this->submit($token)->assertCreated();

        // A customer token never reaches provider routes at all; and the provider account cannot be switched to yet.
        $this->asAccount($token)->getJson('/api/v1/provider/activities')->assertForbidden();
        $this->asAccount($token)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertStatus(422);
    }

    // --- Approval opens the provider account ------------------------------------------------------

    public function test_once_approved_the_customer_can_switch_and_work_as_a_provider_while_staying_a_customer(): void
    {
        [$user] = $this->makeCustomer();
        $customerToken = $this->tokenFor($user, 'customer');
        $this->submit($customerToken)->assertCreated();
        $this->decide(ProviderRequest::firstOrFail(), 'approve');
        $category = $this->makeCategory();

        $switch = $this->asAccount($customerToken)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertOk()
            ->assertJsonPath('data.account.account_type', 'provider')
            ->assertJsonPath('data.account.available_accounts', ['customer', 'provider'])
            ->assertJsonPath('data.profile.business_name', 'Jeddah Divers');

        $providerToken = $switch->json('data.token');
        $this->asAccount($providerToken)->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'First trip'])->assertCreated();

        // And back: still a customer, with the same phone.
        $back = $this->asAccount($providerToken)->postJson('/api/v1/account/switch', ['account_type' => 'customer'])->assertOk();
        $this->asAccount($back->json('data.token'))->getJson('/api/v1/customer/profile')->assertOk()->assertJsonPath('data.phone', $user->phone);
        $this->assertSame('customer', $user->fresh()->user_type);
    }

    public function test_a_provider_who_registered_that_way_works_as_soon_as_the_request_is_approved(): void
    {
        [,, $token] = $this->makeProvider('pending');
        $this->submit($token)->assertCreated();
        $category = $this->makeCategory();
        $this->asAccount($token)->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'Too early'])->assertForbidden();

        $this->decide(ProviderRequest::firstOrFail(), 'approve');

        $this->asAccount($token)->postJson('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'Now allowed'])->assertCreated();
    }
}
