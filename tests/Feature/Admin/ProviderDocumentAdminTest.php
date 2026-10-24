<?php

namespace Tests\Feature\Admin;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\User;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The admin side of verification documents: reading them is the only way a stored
 * file leaves the private disk, so it is limited to admins who may view providers,
 * always an attachment, and every value shown on the page is escaped.
 */
class ProviderDocumentAdminTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function makeDocument(Provider $provider, array $attributes = [], string $content = '%PDF-1.4 licence'): ProviderDocument
    {
        $path = "provider-documents/{$provider->uuid}/".uniqid().'.pdf';
        Storage::disk('local')->put($path, $content);

        return ProviderDocument::create(array_merge([
            'provider_id' => $provider->id,
            'type' => 'business_license',
            'title' => 'Licence 2026',
            'original_name' => 'licence.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
        ], $attributes));
    }

    private function downloadUrl(Provider $provider, ProviderDocument $document): string
    {
        return route('admin.providers.documents.download', ['provider' => $provider, 'document' => $document->uuid]);
    }

    // --- The provider page ------------------------------------------------------

    public function test_the_provider_page_lists_the_documents_with_a_download_link(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider, ['title' => 'Dive centre licence']);

        $page = $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.providers.show', $provider));

        $page->assertOk()
            ->assertSee('Dive centre licence')
            ->assertSee('licence.pdf')
            ->assertSee(__('admin.providers.document_types.business_license'))
            ->assertSee($this->downloadUrl($provider, $document));
    }

    public function test_the_provider_page_says_so_when_no_documents_were_uploaded(): void
    {
        [, $provider] = $this->makeProvider('pending');

        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.providers.show', $provider))
            ->assertOk()
            ->assertSee(__('admin.providers.no_documents'));
    }

    public function test_titles_and_file_names_are_escaped_on_the_page(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $this->makeDocument($provider, [
            'title' => '<script>alert("title")</script>',
            'original_name' => '<img src=x onerror=alert(1)>.pdf',
        ]);

        $page = $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.providers.show', $provider));

        $page->assertOk()
            ->assertDontSee('<script>alert("title")</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_the_provider_list_shows_how_many_documents_each_provider_has(): void
    {
        [, $withDocs] = $this->makeProvider('pending');
        [, $without] = $this->makeProvider('pending');
        $this->makeDocument($withDocs);
        $this->makeDocument($withDocs, ['type' => 'insurance']);

        $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.providers.index'))->assertOk();

        $rows = app(ProviderRepositoryInterface::class)->paginateForAdmin([])->getCollection()->keyBy('id');
        $this->assertSame(2, $rows[$withDocs->id]->documents_count);
        $this->assertSame(0, $rows[$without->id]->documents_count);
    }

    // --- Downloading ------------------------------------------------------------

    public function test_an_admin_can_download_a_document_as_an_attachment(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider, ['original_name' => 'licence.pdf'], '%PDF-1.4 the real bytes');

        $response = $this->actingAs($this->makeAdmin(), 'web')->get($this->downloadUrl($provider, $document));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('licence.pdf');
        $this->assertSame('%PDF-1.4 the real bytes', $response->streamedContent());
    }

    public function test_a_document_paired_with_the_wrong_provider_is_not_found(): void
    {
        [, $owner] = $this->makeProvider('pending');
        [, $someoneElse] = $this->makeProvider('pending');
        $document = $this->makeDocument($owner);

        $this->actingAs($this->makeAdmin(), 'web')
            ->get($this->downloadUrl($someoneElse, $document))
            ->assertNotFound();
    }

    public function test_a_document_whose_file_has_gone_missing_is_not_found(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider);
        Storage::disk('local')->delete($document->path);

        $this->actingAs($this->makeAdmin(), 'web')->get($this->downloadUrl($provider, $document))->assertNotFound();
    }

    // --- Who may download -------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider);

        $this->get($this->downloadUrl($provider, $document))->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_without_permission_to_view_providers_is_refused(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider);
        $noRole = $this->makeAdmin('super_admin');
        $noRole->syncRoles([]);

        $this->actingAs($noRole, 'web')->get($this->downloadUrl($provider, $document))->assertForbidden();
    }

    public function test_a_provider_or_customer_cannot_download_through_the_admin_route(): void
    {
        // The admin middleware does not answer a non-admin with 403: it signs them out of
        // the admin guard and sends them to the login page. Either way no file is served.
        [$providerUser, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider, [], '%PDF-1.4 secret evidence');

        $asProvider = $this->actingAs($providerUser, 'web')->get($this->downloadUrl($provider, $document));
        $asProvider->assertRedirect(route('admin.login'));
        $this->assertStringNotContainsString('secret evidence', (string) $asProvider->getContent());

        $this->app['auth']->forgetGuards();
        $asCustomer = $this->actingAs(User::factory()->customer()->create(), 'web')->get($this->downloadUrl($provider, $document));
        $asCustomer->assertRedirect(route('admin.login'));
        $this->assertStringNotContainsString('secret evidence', (string) $asCustomer->getContent());
    }

    public function test_the_stored_file_cannot_be_fetched_by_guessing_its_storage_url(): void
    {
        [, $provider] = $this->makeProvider('pending');
        $document = $this->makeDocument($provider);

        $status = $this->get('/storage/'.$document->path)->getStatusCode();

        $this->assertContains($status, [403, 404], 'The private disk must not be reachable by URL.');
    }
}
