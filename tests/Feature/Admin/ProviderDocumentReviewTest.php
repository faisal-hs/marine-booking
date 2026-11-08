<?php

namespace Tests\Feature\Admin;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\User;
use App\Models\VerificationDocumentType;
use App\Notifications\Providers\Admin\ProviderDocumentUploadedAdminNotification;
use App\Notifications\Providers\ProviderDocumentReviewedNotification;
use App\Services\ProviderDocumentService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * What an admin does with a provider's documents: approve one (optionally until a date), reject one (with a reason), or ask for a new version (with a
 * reason). Only something the provider SENT IN can be decided; every decision is in the history with who made it; the provider is told.
 */
class ProviderDocumentReviewTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private User $admin;

    private User $providerUser;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeAdmin();
        [$this->providerUser, $this->provider] = $this->makeProvider('verified');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    /** A document the provider sent in, with its upload in the history. */
    private function document(string $type = 'safety_certification', array $attrs = [], ?Provider $for = null): ProviderDocument
    {
        $for ??= $this->provider;
        $path = "provider-documents/{$for->uuid}/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 '.$type);
        $document = ProviderDocument::create($attrs + ['provider_id' => $for->id, 'type' => $type, 'original_name' => "{$type}.pdf", 'path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => 20, 'status' => 'pending']);
        ProviderDocumentEvent::create(['provider_id' => $for->id, 'document_type' => $type, 'provider_document_id' => $document->id, 'action' => 'submitted', 'created_at' => now()]);

        return $document;
    }

    private function approve(ProviderDocument $d, array $data = [], ?User $as = null, ?Provider $p = null)
    {
        return $this->as($as ?? $this->admin)->post(route('admin.providers.documents.approve', ['provider' => $p ?? $this->provider, 'document' => $d->uuid]), $data);
    }

    private function reject(ProviderDocument $d, array $data = [], ?User $as = null)
    {
        return $this->as($as ?? $this->admin)->post(route('admin.providers.documents.reject', ['provider' => $this->provider, 'document' => $d->uuid]), $data);
    }

    private function ask(string $type, array $data = [], ?User $as = null)
    {
        return $this->as($as ?? $this->admin)->post(route('admin.providers.documents.request-update', $this->provider), $data + ['document_type' => $type, 'reason' => 'Please send a clearer scan']);
    }

    private function told(?string $action = null): int
    {
        return $this->providerUser->notifications()->where('type', ProviderDocumentReviewedNotification::class)
            ->when($action, fn ($q) => $q->where('data->type', "provider.document.{$action}"))->count();
    }

    // --- Approving -------------------------------------------------------------------------------------------------

    public function test_approving_records_who_and_when_and_tells_the_provider(): void
    {
        $document = $this->document();

        $this->approve($document)->assertSessionHasNoErrors()->assertSessionHas('status', __('admin.verification_documents.done.approved'));

        $document->refresh();
        $this->assertSame(['approved', $this->admin->id, '2026-11-15 12:00:00', null, null], [$document->status, $document->reviewed_by_user_id, $document->reviewed_at->toDateTimeString(), $document->review_note, $document->expires_at]);
        $event = ProviderDocumentEvent::where('action', 'approved')->firstOrFail();
        $this->assertSame([$this->provider->id, 'safety_certification', $document->id, $this->admin->id], [$event->provider_id, $event->document_type, $event->provider_document_id, $event->actor_user_id]);
        $this->assertSame(1, $this->told('approved'));
        $this->assertStringContainsString('Safety certification was approved', $this->providerUser->notifications()->latest()->first()->data['message']);
    }

    public function test_an_approval_can_carry_a_valid_until_date_that_must_be_in_the_future(): void
    {
        $document = $this->document();

        foreach (['2026-11-15', '2026-11-01', 'soon'] as $bad) {
            $this->approve($document, ['expires_at' => $bad])->assertSessionHasErrors('expires_at');
        }
        $this->assertSame('pending', $document->refresh()->status, 'A refused approval changes nothing');

        $this->approve($document, ['expires_at' => '2027-03-01'])->assertSessionHasNoErrors();
        $this->assertSame('2027-03-01', $document->refresh()->expires_at->toDateString());
    }

    // --- Rejecting -------------------------------------------------------------------------------------------------

    public function test_rejecting_needs_a_reason_and_tells_the_provider_why(): void
    {
        $document = $this->document();

        foreach (['', 'no', str_repeat('x', 501)] as $bad) {
            $this->reject($document, ['note' => $bad])->assertSessionHasErrors('note');
        }
        $this->assertSame('pending', $document->refresh()->status);
        $this->assertSame(0, $this->told());

        $this->reject($document, ['note' => 'The document is not legible'])->assertSessionHas('status', __('admin.verification_documents.done.rejected'));

        $document->refresh();
        $this->assertSame(['rejected', 'The document is not legible', $this->admin->id], [$document->status, $document->review_note, $document->reviewed_by_user_id]);
        $this->assertSame('The document is not legible', ProviderDocumentEvent::where('action', 'rejected')->first()->note);
        $this->assertStringContainsString('The document is not legible', $this->providerUser->notifications()->latest()->first()->data['message']);
    }

    // --- Only what was sent in can be decided --------------------------------------------------------------------

    public function test_a_decision_is_only_ever_about_something_waiting(): void
    {
        $approved = $this->document('insurance', ['status' => 'approved']);
        $rejected = $this->document('business_license', ['status' => 'rejected', 'review_note' => 'No']);

        foreach ([$approved, $rejected] as $document) {
            $this->approve($document)->assertSessionHasErrors('document');
            $this->reject($document, ['note' => 'Changed my mind'])->assertSessionHasErrors('document');
        }

        $this->assertSame(['approved', 'rejected'], [$approved->refresh()->status, $rejected->refresh()->status]);
        $this->assertSame(0, ProviderDocumentEvent::whereIn('action', ['approved', 'rejected'])->count());
    }

    public function test_a_replaced_version_cannot_be_decided(): void
    {
        $old = $this->document();
        $old->update(['superseded_at' => now()]);

        $this->approve($old)->assertSessionHasErrors('document');

        $this->assertSame('pending', $old->refresh()->status);
    }

    public function test_deciding_twice_in_a_row_does_nothing_the_second_time(): void
    {
        $document = $this->document();

        $this->approve($document)->assertSessionHasNoErrors();
        $this->approve($document)->assertSessionHasErrors('document');
        $this->reject($document, ['note' => 'Too late'])->assertSessionHasErrors('document');

        $this->assertSame(1, ProviderDocumentEvent::where('action', 'approved')->count());
        $this->assertSame(1, $this->told());
    }

    public function test_another_providers_document_through_this_providers_url_is_not_found(): void
    {
        [, $other] = $this->makeProvider('verified');
        $theirs = $this->document('safety_certification', [], $other);

        $this->approve($theirs)->assertNotFound();

        $this->assertSame('pending', $theirs->refresh()->status);
    }

    // --- Asking for an update --------------------------------------------------------------------------------------------

    public function test_an_admin_asks_for_a_new_version_with_a_reason_and_the_provider_is_told(): void
    {
        $this->document('safety_certification', ['status' => 'approved']);

        $this->ask('safety_certification', ['reason' => 'The business name changed'])->assertSessionHas('status', __('admin.verification_documents.done.update_requested'));

        $event = ProviderDocumentEvent::where('action', 'update_requested')->firstOrFail();
        $this->assertSame(['The business name changed', $this->admin->id, 'safety_certification'], [$event->note, $event->actor_user_id, $event->document_type]);
        $this->assertSame(1, $this->told('update_requested'));
        $this->assertStringContainsString('The business name changed', $this->providerUser->notifications()->latest()->first()->data['message']);
    }

    public function test_a_type_can_be_asked_for_even_when_nothing_is_on_file(): void
    {
        $this->ask('business_license')->assertSessionHasNoErrors();

        $event = ProviderDocumentEvent::where('action', 'update_requested')->firstOrFail();
        $this->assertNull($event->provider_document_id);
    }

    public function test_a_reason_and_a_real_active_type_are_required(): void
    {
        VerificationDocumentType::where('key', 'insurance')->update(['is_active' => false]);

        $this->ask('safety_certification', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->ask('safety_certification', ['reason' => 'ab'])->assertSessionHasErrors('reason');
        $this->ask('no_such_document')->assertSessionHasErrors('document_type');
        $this->ask('insurance')->assertSessionHasErrors('document_type');

        $this->assertSame(0, ProviderDocumentEvent::where('action', 'update_requested')->count());
    }

    public function test_a_second_request_while_one_is_open_is_refused_and_a_new_one_is_allowed_after_the_upload(): void
    {
        $this->document('safety_certification', ['status' => 'approved']);
        $this->ask('safety_certification')->assertSessionHasNoErrors();

        $this->ask('safety_certification')->assertSessionHasErrors('document_type');
        $this->assertSame(1, ProviderDocumentEvent::where('action', 'update_requested')->count());

        app(ProviderDocumentService::class)->replace($this->provider->refresh(), 'safety_certification', UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'));

        $this->ask('safety_certification')->assertSessionHasNoErrors();
        $this->assertSame(2, ProviderDocumentEvent::where('action', 'update_requested')->count());
    }

    // --- Who may ---------------------------------------------------------------------------------------------------

    public function test_only_admins_with_the_review_permission_may_decide_or_ask(): void
    {
        $document = $this->document();

        foreach (['content_admin', 'support_admin'] as $role) {
            $staff = $this->makeAdmin($role);
            $this->approve($document, [], $staff)->assertForbidden();
            $this->reject($document, ['note' => 'Not allowed'], $staff)->assertForbidden();
            $this->ask('insurance', [], $staff)->assertForbidden();
            $this->as($staff)->get(route('admin.verification-documents.index'))->assertForbidden();
        }
        $this->assertSame(['pending', 0], [$document->refresh()->status, ProviderDocumentEvent::whereNot('action', 'submitted')->count()]);

        foreach (['admin', 'super_admin'] as $role) {
            $this->as($this->makeAdmin($role))->get(route('admin.verification-documents.index'))->assertOk();
        }
        $this->approve($document, [], $this->makeAdmin('admin'))->assertSessionHasNoErrors();
    }

    public function test_a_refused_admin_cannot_tell_a_real_document_id_from_a_made_up_one(): void
    {
        $document = $this->document();
        $staff = $this->makeAdmin('content_admin');
        $fake = (string) Str::uuid();

        $real = $this->as($staff)->post(route('admin.providers.documents.approve', ['provider' => $this->provider, 'document' => $document->uuid]));
        $made = $this->as($staff)->post(route('admin.providers.documents.approve', ['provider' => $this->provider, 'document' => $fake]));
        $this->assertSame([403, 403], [$real->getStatusCode(), $made->getStatusCode()]);

        $real = $this->as($staff)->post(route('admin.providers.documents.reject', ['provider' => $this->provider, 'document' => $document->uuid]), ['note' => 'No way']);
        $made = $this->as($staff)->post(route('admin.providers.documents.reject', ['provider' => $this->provider, 'document' => $fake]), ['note' => 'No way']);
        $this->assertSame([403, 403], [$real->getStatusCode(), $made->getStatusCode()]);

        $noDownload = $this->makeAdmin('support_admin');
        $noDownload->revokePermissionTo($noDownload->getAllPermissions());
        $noDownload->syncRoles([]);
        $this->assertSame(
            [$this->as($noDownload)->get(route('admin.providers.documents.download', ['provider' => $this->provider, 'document' => $document->uuid]))->getStatusCode()],
            [$this->as($noDownload)->get(route('admin.providers.documents.download', ['provider' => $this->provider, 'document' => $fake]))->getStatusCode()],
            'A real and a made-up id are answered alike'
        );
    }

    public function test_a_provider_and_a_customer_cannot_reach_the_review_routes(): void
    {
        $document = $this->document();

        $this->approve($document, [], $this->providerUser)->assertRedirect(route('admin.login'));
        $this->approve($document, [], User::factory()->customer()->create())->assertRedirect(route('admin.login'));

        $this->assertSame('pending', $document->refresh()->status);
    }

    // --- The queue ---------------------------------------------------------------------------------------------------

    public function test_the_queue_opens_on_what_waits_oldest_first_and_leaves_out_replaced_versions(): void
    {
        $first = $this->document('safety_certification', ['created_at' => '2026-11-01 09:00:00']);
        $second = $this->document('insurance_certificate', ['created_at' => '2026-11-10 09:00:00']);
        $this->document('business_license', ['status' => 'approved']);
        $replaced = $this->document('insurance', ['superseded_at' => now()]);

        $page = $this->as($this->admin)->get(route('admin.verification-documents.index'))->assertOk();

        $page->assertSee(__('admin.nav.verification_documents'))->assertSeeInOrder(['safety_certification.pdf', 'insurance_certificate.pdf'])
            ->assertDontSee('business_license.pdf')->assertDontSee('insurance.pdf');
        $page->assertSee(__('admin.verification_documents.statuses.pending').' (2)', false)->assertSee(__('admin.verification_documents.statuses.approved').' (1)', false)->assertSee(__('admin.verification_documents.statuses.all').' (3)', false);
        $this->assertNotNull($replaced->refresh()->superseded_at);

        $this->as($this->admin)->get(route('admin.verification-documents.index', ['status' => 'approved']))->assertSee('business_license.pdf')->assertDontSee('safety_certification.pdf');
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->as($this->admin)->get(route('admin.verification-documents.index'))->assertOk()->assertSee(__('admin.verification_documents.none'));
    }

    // --- The provider's page -----------------------------------------------------------------------------------------

    public function test_the_provider_page_shows_each_type_with_its_status_and_only_the_actions_that_make_sense(): void
    {
        $waiting = $this->document('safety_certification');
        $done = $this->document('insurance_certificate', ['status' => 'approved']);
        $this->ask('insurance_certificate');

        $page = $this->as($this->admin)->get(route('admin.providers.show', $this->provider))->assertOk();

        $page->assertSee(__('admin.verification_documents.statuses.pending'))->assertSee(__('admin.verification_documents.statuses.update_requested'))->assertSee(__('admin.verification_documents.statuses.missing'))
            ->assertSee(route('admin.providers.documents.approve', ['provider' => $this->provider, 'document' => $waiting->uuid]))
            ->assertSee(route('admin.providers.documents.reject', ['provider' => $this->provider, 'document' => $waiting->uuid]))
            ->assertDontSee(route('admin.providers.documents.approve', ['provider' => $this->provider, 'document' => $done->uuid]))
            ->assertSee(route('admin.providers.documents.download', ['provider' => $this->provider, 'document' => $done->uuid]))
            ->assertSee(__('admin.document_types.mandatory'));
        $this->assertStringNotContainsString('value="insurance_certificate"', $page->getContent(), 'No second request is offered for a type that has one open');
        $this->assertStringContainsString('value="safety_certification"', $page->getContent(), 'A type without an open request can be asked');
    }

    public function test_the_page_shows_the_history_with_who_did_what_and_why(): void
    {
        $document = $this->document();
        $this->reject($document, ['note' => 'The document is not legible']);

        $page = $this->as($this->admin)->get(route('admin.providers.show', $this->provider))->assertOk();

        $page->assertSee(__('admin.verification_documents.history'))->assertSee(__('admin.verification_documents.events.submitted'))->assertSee(__('admin.verification_documents.events.rejected'))
            ->assertSee($this->admin->name)->assertSee('The document is not legible');
    }

    public function test_an_admin_can_still_download_a_replaced_version(): void
    {
        $old = $this->document('safety_certification');
        $old->update(['superseded_at' => now()]);

        $this->as($this->admin)->get(route('admin.providers.documents.download', ['provider' => $this->provider, 'document' => $old->uuid]))->assertOk();
    }

    public function test_a_document_of_a_switched_off_type_is_still_listed_for_the_admin(): void
    {
        $this->document('insurance');
        VerificationDocumentType::where('key', 'insurance')->update(['is_active' => false]);

        $this->as($this->admin)->get(route('admin.providers.show', $this->provider))->assertOk()->assertSee(__('admin.verification_documents.other_documents'))->assertSee('insurance.pdf');
    }

    // --- Who is told about an upload --------------------------------------------------------------------------------------

    public function test_the_reviewers_are_told_when_a_provider_uploads_and_nobody_else(): void
    {
        $content = $this->makeAdmin('content_admin');
        $other = $this->makeAdmin('admin');

        app(ProviderDocumentService::class)->replace($this->provider, 'safety_certification', UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'));

        foreach ([$this->admin, $other] as $reviewer) {
            $note = $reviewer->notifications()->where('type', ProviderDocumentUploadedAdminNotification::class)->get();
            $this->assertCount(1, $note);
            $this->assertStringContainsString($this->provider->business_name, $note[0]->data['message']);
        }
        $this->assertSame(0, $content->notifications()->count());
        $this->assertSame(0, $this->providerUser->notifications()->count(), 'The provider is not told about their own upload');
    }

    public function test_an_upload_made_while_applying_does_not_notify_the_reviewers_a_second_time(): void
    {
        app(ProviderDocumentService::class)->replace($this->provider, 'safety_certification', UploadedFile::fake()->create('s.pdf', 10, 'application/pdf'), notify: false);

        $this->assertSame(0, $this->admin->notifications()->count());
        $this->assertSame(1, ProviderDocumentEvent::where('action', 'submitted')->count(), 'It is still in the history');
    }

    // --- Verifying and rejecting the provider -----------------------------------------------------------------------

    public function test_verifying_the_provider_approves_the_documents_that_were_waiting_and_says_so(): void
    {
        [, $pending] = $this->makeProvider('pending');
        $waiting = [$this->document('commercial_registration', [], $pending), $this->document('safety_certification', [], $pending)];
        $turnedDown = $this->document('insurance_certificate', ['status' => 'rejected', 'review_note' => 'Blurry'], $pending);

        $this->as($this->admin)->post(route('admin.providers.verify', $pending))->assertSessionHasNoErrors();

        foreach ($waiting as $document) {
            $document->refresh();
            $this->assertSame(['approved', $this->admin->id], [$document->status, $document->reviewed_by_user_id]);
        }
        $this->assertSame('rejected', $turnedDown->refresh()->status, 'A document an admin rejected stays rejected');
        $events = ProviderDocumentEvent::where('provider_id', $pending->id)->where('action', 'approved')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['Approved together with the provider.'], $events->pluck('note')->unique()->all());
        $this->assertSame(0, $pending->user->notifications()->where('type', ProviderDocumentReviewedNotification::class)->count(), 'Told once that the account was verified, not once per document');
    }

    public function test_rejecting_the_provider_leaves_the_documents_waiting_for_the_next_application(): void
    {
        [, $pending] = $this->makeProvider('pending');
        $document = $this->document('safety_certification', [], $pending);

        $this->as($this->admin)->post(route('admin.providers.reject', $pending), ['rejection_reason' => 'Documents do not match'])->assertSessionHasNoErrors();

        $this->assertSame('pending', $document->refresh()->status);
    }

    public function test_approving_a_pending_document_directly_does_not_verify_the_provider(): void
    {
        [, $pending] = $this->makeProvider('pending');
        $document = $this->document('safety_certification', [], $pending);

        $this->approve($document, [], null, $pending)->assertSessionHasNoErrors();

        $this->assertSame('pending', $pending->refresh()->verification_status, 'Approving the provider is its own decision');
    }
}
