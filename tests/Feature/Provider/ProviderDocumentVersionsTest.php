<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\User;
use App\Models\VerificationDocumentType;
use App\Notifications\Providers\Admin\ProviderDocumentUploadedAdminNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The older /provider/documents API under the new rules: an upload is pending and in the history, the list shows only the versions in force and where
 * each stands, and a replaced version is the record an earlier decision was based on, so a provider cannot delete it.
 */
class ProviderDocumentVersionsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private User $user;

    private $provider;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
        [$this->user, $this->provider, $this->token] = $this->makeProvider('verified');
    }

    private function store(string $type = 'business_license', ?UploadedFile $file = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->asProvider($this->token)->post('/api/v1/provider/documents', ['type' => $type, 'file' => $file ?? UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf')], ['Accept' => 'application/json']);
    }

    public function test_an_upload_is_pending_and_in_the_history_and_the_reviewers_are_told(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->store()->assertCreated();

        $this->assertSame(['pending', null, null], [$response->json('data.status'), $response->json('data.review_note'), $response->json('data.expires_at')]);
        $this->assertSame(['submitted'], ProviderDocumentEvent::pluck('action')->all());
        $this->assertSame(1, $admin->notifications()->where('type', ProviderDocumentUploadedAdminNotification::class)->count());
    }

    public function test_only_the_types_an_admin_allows_can_be_uploaded(): void
    {
        VerificationDocumentType::where('key', 'insurance')->update(['is_active' => false]);
        VerificationDocumentType::create(['key' => 'boat_registration', 'title_en' => 'Boat registration', 'title_ar' => 'تسجيل القارب']);

        $this->store('insurance')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->store('nonsense')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->store('boat_registration')->assertCreated();
    }

    public function test_the_list_shows_what_is_in_force_with_its_review_status(): void
    {
        $this->store('business_license')->assertCreated();
        $rejected = ProviderDocument::create(['provider_id' => $this->provider->id, 'type' => 'insurance', 'original_name' => 'i.pdf', 'path' => 'p/i.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'rejected', 'review_note' => 'Blurry']);
        ProviderDocument::create(['provider_id' => $this->provider->id, 'type' => 'other', 'original_name' => 'o.pdf', 'path' => 'p/o.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'approved', 'expires_at' => '2020-01-01']);
        ProviderDocument::create(['provider_id' => $this->provider->id, 'type' => 'insurance', 'original_name' => 'old.pdf', 'path' => 'p/old.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'approved', 'superseded_at' => now()]);

        $this->app['auth']->forgetGuards();
        $list = collect($this->asProvider($this->token)->getJson('/api/v1/provider/documents')->assertOk()->json('data'));

        $this->assertCount(3, $list, 'The replaced version is not listed');
        $this->assertSame(['pending', null], [$list->firstWhere('type', 'business_license')['status'], $list->firstWhere('type', 'business_license')['review_note']]);
        $this->assertSame(['rejected', 'Blurry'], [$list->firstWhere('type', 'insurance')['status'], $list->firstWhere('type', 'insurance')['review_note']]);
        $this->assertSame('expired', $list->firstWhere('type', 'other')['status']);
        $this->assertNotContains('old.pdf', $list->pluck('original_name')->all());
        $this->assertNotNull($rejected->refresh());
    }

    public function test_a_provider_cannot_delete_a_replaced_version_even_by_its_id(): void
    {
        $old = ProviderDocument::create(['provider_id' => $this->provider->id, 'type' => 'other', 'original_name' => 'old.pdf', 'path' => 'p/old.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'approved', 'superseded_at' => now()]);

        $this->app['auth']->forgetGuards();
        $this->asProvider($this->token)->deleteJson("/api/v1/provider/documents/{$old->uuid}")->assertNotFound();

        $this->assertNotNull(ProviderDocument::find($old->id));
    }

    public function test_deleting_the_version_in_force_keeps_the_history(): void
    {
        $this->store('other')->assertCreated();
        $document = ProviderDocument::firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->asProvider($this->token)->deleteJson("/api/v1/provider/documents/{$document->uuid}")->assertSuccessful();

        $this->assertNull(ProviderDocument::find($document->id));
        $event = ProviderDocumentEvent::firstOrFail();
        $this->assertSame(['submitted', 'other', null], [$event->action, $event->document_type, $event->provider_document_id], 'The history stays, no longer pointing at the file');
    }

    public function test_the_mandatory_documents_of_an_approved_provider_stay_but_only_while_they_are_mandatory(): void
    {
        $this->store('safety_certification')->assertCreated();
        $document = ProviderDocument::firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->asProvider($this->token)->deleteJson("/api/v1/provider/documents/{$document->uuid}")->assertStatus(422);
        $this->assertNotNull(ProviderDocument::find($document->id));

        VerificationDocumentType::where('key', 'safety_certification')->update(['is_mandatory' => false]);

        $this->app['auth']->forgetGuards();
        $this->asProvider($this->token)->deleteJson("/api/v1/provider/documents/{$document->uuid}")->assertSuccessful();
        $this->assertNull(ProviderDocument::find($document->id));
    }
}
