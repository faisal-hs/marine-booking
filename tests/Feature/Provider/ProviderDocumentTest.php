<?php

namespace Tests\Feature\Provider;

use App\Models\ProviderDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Providers upload the evidence an admin verifies them from (business licence,
 * certificates, insurance). It is evidence about a business, so it lives on a
 * private disk and is never exposed by path.
 */
class ProviderDocumentTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function upload(string $token, array $data = [], ?UploadedFile $file = null)
    {
        return $this->asProvider($token)->post('/api/v1/provider/documents', array_merge([
            'type' => 'business_license',
            'title' => 'Licence 2026',
            'file' => $file ?? UploadedFile::fake()->create('licence.pdf', 200),
        ], $data), ['Accept' => 'application/json']);
    }

    // --- Uploading --------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function acceptedFiles(): array
    {
        return ['a PDF' => ['licence.pdf'], 'a JPEG' => ['licence.jpg'], 'a JPEG (.jpeg)' => ['licence.jpeg'], 'a PNG' => ['licence.png'], 'a WebP' => ['licence.webp']];
    }

    #[DataProvider('acceptedFiles')]
    public function test_a_provider_can_upload_a_document(string $filename): void
    {
        [, $provider, $token] = $this->makeProvider('pending');

        $response = $this->upload($token, [], UploadedFile::fake()->create($filename, 100));

        $response->assertCreated()
            ->assertJsonPath('data.type', 'business_license')
            ->assertJsonPath('data.title', 'Licence 2026')
            ->assertJsonPath('data.original_name', $filename)
            ->assertJsonMissingPath('data.path');

        $document = ProviderDocument::firstOrFail();
        $this->assertSame($provider->id, $document->provider_id);
    }

    public function test_the_file_is_kept_on_the_private_disk_and_never_on_the_public_one(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');

        $this->upload($token)->assertCreated();

        $document = ProviderDocument::firstOrFail();
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsWith("provider-documents/{$provider->uuid}/", $document->path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'A verification document must never reach the public disk.');
    }

    public function test_the_stored_name_comes_from_the_server_not_from_the_upload(): void
    {
        [, , $token] = $this->makeProvider('pending');

        $this->upload($token, [], UploadedFile::fake()->create('../../etc/evil<script>.pdf', 10))->assertCreated();

        $document = ProviderDocument::firstOrFail();
        $this->assertStringNotContainsString('evil', $document->path);
        $this->assertStringNotContainsString('..', $document->path);
        // The display name is kept for screens, but cleaned: no path parts, no markup.
        $this->assertStringNotContainsString('/', $document->original_name);
        $this->assertStringNotContainsString('<', $document->original_name);
    }

    /** @return array<string, array{0: string, 1: string}> [filename, content] */
    public static function rejectedFiles(): array
    {
        return [
            'an SVG, which can carry a script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            'an HTML page' => ['page.html', '<html><script>alert(1)</script></html>'],
            'an executable' => ['setup.exe', 'MZ'],
            'a script' => ['run.php', '<?php echo 1;'],
            'plain text' => ['notes.txt', 'hello'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_other_file_types_are_rejected(string $filename, string $content): void
    {
        [, , $token] = $this->makeProvider('pending');

        $this->upload($token, [], UploadedFile::fake()->createWithContent($filename, $content))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, ProviderDocument::count());
    }

    public function test_the_size_limit_is_enforced(): void
    {
        [, , $token] = $this->makeProvider('pending');
        $limit = config('providers.documents.max_kb');

        $this->upload($token, [], UploadedFile::fake()->create('big.pdf', $limit + 1))->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->upload($token, [], UploadedFile::fake()->create('just-fits.pdf', $limit))->assertCreated();
    }

    public function test_the_type_the_title_and_the_file_are_validated(): void
    {
        [, , $token] = $this->makeProvider('pending');

        $this->upload($token, ['type' => 'selfie'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->upload($token, ['type' => ''])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->upload($token, ['title' => str_repeat('x', 121)])->assertStatus(422)->assertJsonValidationErrors(['title']);

        $this->asProvider($token)->postJson('/api/v1/provider/documents', ['type' => 'insurance'])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertSame(0, ProviderDocument::count());
    }

    public function test_a_provider_can_keep_at_most_the_configured_number_of_documents(): void
    {
        [, , $token] = $this->makeProvider('pending');
        $limit = config('providers.documents.max_per_provider');

        for ($i = 0; $i < $limit; $i++) {
            $this->upload($token, ['title' => "Document {$i}"])->assertCreated();
        }

        $this->upload($token)->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertSame($limit, ProviderDocument::count());
        $this->assertCount($limit, Storage::disk('local')->allFiles(), 'A refused upload must not leave a file behind.');
    }

    public function test_uploading_a_document_does_not_verify_the_provider(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');

        $this->upload($token)->assertCreated();

        $this->assertSame('pending', $provider->fresh()->verification_status);
    }

    public function test_a_verified_provider_can_still_upload_a_renewed_document(): void
    {
        [, , $token] = $this->makeProvider('verified');

        $this->upload($token, ['type' => 'insurance'])->assertCreated();
    }

    // --- Listing and deleting -----------------------------------------------------

    public function test_a_provider_sees_only_their_own_documents(): void
    {
        [, , $tokenA] = $this->makeProvider('pending');
        [, , $tokenB] = $this->makeProvider('pending');

        $this->upload($tokenA, ['title' => 'Mine'])->assertCreated();

        // Switching users inside one test needs a fresh auth guard, or the first user sticks.
        $this->app['auth']->forgetGuards();
        $this->upload($tokenB, ['title' => 'Theirs'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $response = $this->asProvider($tokenA)->getJson('/api/v1/provider/documents')->assertOk();

        $this->assertSame(['Mine'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_a_provider_can_delete_their_own_document_and_the_file_goes_with_it(): void
    {
        [, , $token] = $this->makeProvider('pending');
        $this->upload($token)->assertCreated();
        $document = ProviderDocument::firstOrFail();

        $this->asProvider($token)->deleteJson("/api/v1/provider/documents/{$document->uuid}")->assertOk();

        $this->assertSame(0, ProviderDocument::count());
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_a_verified_provider_cannot_delete_the_documents_they_were_verified_on(): void
    {
        [, $provider, $token] = $this->makeProvider('pending');
        $this->upload($token)->assertCreated();
        $document = ProviderDocument::firstOrFail();
        $provider->update(['verification_status' => 'verified']);

        $this->asProvider($token)->deleteJson("/api/v1/provider/documents/{$document->uuid}")
            ->assertStatus(422)->assertJsonValidationErrors(['document']);

        $this->assertSame(1, ProviderDocument::count());
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_someone_elses_document_is_not_found_and_stays_untouched(): void
    {
        [, , $tokenA] = $this->makeProvider('pending');
        [, , $tokenB] = $this->makeProvider('pending');
        $this->upload($tokenA)->assertCreated();
        $document = ProviderDocument::firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->asProvider($tokenB)->deleteJson("/api/v1/provider/documents/{$document->uuid}")->assertNotFound();

        $this->assertSame(1, ProviderDocument::count());
        Storage::disk('local')->assertExists($document->path);
    }

    // --- Who may use it -----------------------------------------------------------

    public function test_an_anonymous_visitor_is_refused(): void
    {
        $this->getJson('/api/v1/provider/documents')->assertUnauthorized();
        $this->post('/api/v1/provider/documents', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_a_customer_cannot_use_the_provider_documents_api(): void
    {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('test', ['customer'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/provider/documents')->assertForbidden();
    }
}
