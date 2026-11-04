<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ProviderDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Photos and documents are stored and then served back, so an uploaded file that can carry
 * a script (an SVG, an HTML page, a PHP file) must be refused on EVERY endpoint that takes a
 * file, not only the one that was last written.
 *
 * The endpoints that take a file are: activity photos (provider API, with or without a new
 * activity), category images (admin), activity photos (admin) and verification documents.
 * The provider profile and the customer profile accept no file at all.
 *
 * Most image rules in this project are the generic "image" rule. Laravel 12's generic rule
 * rejects SVG by default, which is what keeps these endpoints safe today; this test is what
 * stops that from being an unverified assumption, and would fail if a rule were ever
 * loosened or the framework default changed.
 */
class ImageUploadSecurityTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)"><script>alert(1)</script></svg>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function svg(string $name = 'evil.svg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::SVG);
    }

    private function php(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]);');
    }

    public function test_the_provider_api_rejects_an_svg_as_an_activity_photo(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asProvider($token)->post("/api/v1/provider/activities/{$activity->uuid}/images", ['images' => [$this->svg()]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['images.0']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_provider_api_rejects_an_svg_and_a_php_file_sent_with_a_new_activity(): void
    {
        [,, $token] = $this->makeProvider('verified');
        $category = $this->makeCategory();

        foreach ([$this->svg(), $this->php()] as $file) {
            $this->app['auth']->forgetGuards();
            $this->asProvider($token)->post('/api/v1/provider/activities', ['category_id' => $category->id, 'title' => 'With a bad photo', 'images' => [$file]], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors(['images.0']);
        }

        $this->assertSame(0, Activity::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_admin_category_form_rejects_an_svg_image(): void
    {
        $this->actingAs($this->makeAdmin(), 'web')->post(route('admin.categories.store'), [
            'name' => 'Sneaky',
            'status' => 'active',
            'image' => $this->svg('logo.svg'),
        ])->assertSessionHasErrors('image');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_admin_activity_photo_upload_rejects_an_svg_and_a_php_file(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        foreach ([$this->svg(), $this->php()] as $file) {
            $this->actingAs($this->makeAdmin(), 'web')->post(route('admin.activities.images.store', $activity), ['images' => [$file]])->assertSessionHasErrors('images.0');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_provider_document_upload_rejects_an_svg_html_and_php(): void
    {
        [,, $token] = $this->makeProvider('verified');

        foreach ([$this->svg(), UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'), $this->php()] as $file) {
            $this->app['auth']->forgetGuards();
            $this->asProvider($token)->post('/api/v1/provider/documents', ['type' => 'other', 'file' => $file], ['Accept' => 'application/json'])->assertStatus(422);
        }

        $this->assertSame(0, ProviderDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
