<?php

namespace Tests\Feature\ProviderApp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * The provider's profile picture, through /provider/profile: a form upload as `image` (or `remove_image: true`), on PATCH, on the older PUT, and on a plain POST
 * for a client that cannot send either with files. It changes at once, even when something else in the same request waits for an admin.
 */
class ProviderProfileImageTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const URL = '/api/v1/provider/profile';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->a = $this->approvedProvider();
    }

    private function png(string $name = 'me.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function send(string $method, array $data, ?array $as = null)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $files = array_filter($data, fn ($v) => $v instanceof UploadedFile);
        $fields = array_filter($data, fn ($v) => ! $v instanceof UploadedFile);

        return $this->call($method, self::URL, $fields, [], $files, ['HTTP_AUTHORIZATION' => 'Bearer '.($as ?? $this->a)['token'], 'HTTP_ACCEPT' => 'application/json']);
    }

    private function logo(?array $as = null): ?string
    {
        return ($as ?? $this->a)['provider']->refresh()->logo;
    }

    // --- Adding a picture --------------------------------------------------------------------------------------------

    public function test_a_picture_can_be_set_on_each_of_the_three_verbs(): void
    {
        $previous = null;

        foreach (['PATCH', 'PUT', 'POST'] as $verb) {
            $r = $this->send($verb, ['image' => $this->png()])->assertOk();
            $now = $this->logo();

            $this->assertStringContainsString('providers/logos/', $r->json('data.logo'), $verb);
            $this->assertNotNull($now, $verb);
            $this->assertNotSame($previous, $now, "{$verb} made a NEW picture, not the last verb's");
            $previous = $now;
        }
    }

    public function test_the_picture_is_stored_under_our_name_and_answered_as_a_url(): void
    {
        $r = $this->send('POST', ['image' => $this->png('../../my face!.png')])->assertOk();

        $path = $this->logo();
        $this->assertStringStartsWith('providers/logos/'.$this->a['provider']->uuid.'-', $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('my face', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(Storage::disk('public')->url($path), $r->json('data.logo'));
    }

    public function test_the_picture_alone_is_a_complete_request(): void
    {
        $this->send('PATCH', ['image' => $this->png()])->assertOk();
        $this->send('PATCH', [])->assertStatus(422);   // nothing at all is still refused
    }

    public function test_the_profile_shows_the_picture_and_so_do_customers(): void
    {
        $this->send('POST', ['image' => $this->png()])->assertOk();
        $expected = Storage::disk('public')->url($this->logo());

        $this->assertSame($expected, $this->hit($this->a['token'], 'GET', self::URL)->json('data.logo'));
        $this->assertSame($expected, $this->getJson("/api/v1/customer-app/activities/{$this->a['activity']->id}")->assertOk()->json('data.host.avatar'), 'Customers see it as the host\'s avatar');
    }

    public function test_it_changes_at_once_even_when_the_business_name_waits_for_an_admin(): void
    {
        $name = $this->a['provider']->business_name;

        $r = $this->send('POST', ['business_name' => 'A New Business Name', 'image' => $this->png()])->assertOk();

        $this->assertStringContainsString('waiting for admin review', $r->json('message'));
        $this->assertNotNull($this->logo(), 'The picture is live');
        $this->assertSame($name, $this->a['provider']->refresh()->business_name, 'The business name is not');
    }

    // --- Replacing and removing ---------------------------------------------------------------------------------------

    public function test_a_new_picture_replaces_the_old_and_the_old_file_goes(): void
    {
        $this->send('POST', ['image' => $this->png('a.png')])->assertOk();
        $first = $this->logo();

        $this->send('POST', ['image' => $this->png('b.png')])->assertOk();

        $this->assertNotSame($first, $this->logo());
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($this->logo());
        $this->assertCount(1, Storage::disk('public')->files('providers/logos'));
    }

    public function test_the_picture_can_be_taken_off(): void
    {
        $this->send('POST', ['image' => $this->png()])->assertOk();
        $path = $this->logo();

        $r = $this->send('PATCH', ['remove_image' => true])->assertOk();

        $this->assertNull($r->json('data.logo'));
        $this->assertNull($this->logo());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_remove_image_sent_as_the_word_false_does_nothing_and_true_removes(): void
    {
        $this->send('POST', ['image' => $this->png()])->assertOk();
        $path = $this->logo();

        $this->send('POST', ['business_bio_unused' => 'x', 'remove_image' => 'false', 'bio' => 'Hello'])->assertOk();
        $this->assertSame($path, $this->logo());

        $this->send('POST', ['remove_image' => 'true'])->assertOk();
        $this->assertNull($this->logo());
    }

    public function test_a_picture_and_remove_image_together_are_refused(): void
    {
        $this->send('POST', ['image' => $this->png(), 'remove_image' => true])->assertStatus(422)->assertJsonValidationErrors('remove_image');

        $this->assertNull($this->logo());
    }

    public function test_taking_off_a_picture_that_is_not_there_is_harmless(): void
    {
        $this->send('PATCH', ['remove_image' => true])->assertOk();

        $this->assertNull($this->logo());
    }

    public function test_a_path_that_was_set_by_hand_is_never_deleted_by_a_replacement(): void
    {
        Storage::disk('public')->put('brand/hand-made.png', 'x');
        $this->a['provider']->update(['logo' => 'brand/hand-made.png']);

        $this->send('POST', ['image' => $this->png()])->assertOk();

        Storage::disk('public')->assertExists('brand/hand-made.png');
    }

    // --- What is refused ----------------------------------------------------------------------------------------------

    public function test_only_images_up_to_four_megabytes_are_accepted_and_nothing_changes_when_one_is_refused(): void
    {
        $this->send('POST', ['image' => $this->png()])->assertOk();
        $path = $this->logo();

        foreach ([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('big.png', 5000, 'image/png')] as $bad) {
            $this->send('POST', ['image' => $bad])->assertStatus(422)->assertJsonValidationErrors('image');
        }

        $this->assertSame($path, $this->logo());
        Storage::disk('public')->assertExists($path);
        $this->assertCount(1, Storage::disk('public')->files('providers/logos'));
    }

    public function test_an_image_that_is_not_a_file_is_refused(): void
    {
        $this->send('POST', ['image' => 'not a file'])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    // --- Who ----------------------------------------------------------------------------------------------------------

    public function test_one_providers_picture_is_not_anothers(): void
    {
        $other = $this->approvedProvider();
        $this->send('POST', ['image' => $this->png()], $other)->assertOk();

        $this->assertNull($this->logo());
        $this->assertNotNull($this->logo($other));
    }

    public function test_a_provider_who_is_not_approved_a_customer_and_nobody_cannot_change_it(): void
    {
        $p = $this->unapprovedProvider('pending');
        [$customer] = $this->makeCustomer();

        $this->send('POST', ['image' => $this->png()], $p)->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$this->tokenFor($customer, 'customer'), 'Accept' => 'application/json'])->post(self::URL, ['image' => $this->png()])->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->post(self::URL, ['image' => $this->png()])->assertUnauthorized();

        $this->assertNull($p['provider']->refresh()->logo);
        $this->assertSame([], Storage::disk('public')->files('providers/logos'));
    }
}
