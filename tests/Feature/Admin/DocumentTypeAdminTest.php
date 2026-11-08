<?php

namespace Tests\Feature\Admin;

use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\User;
use App\Models\VerificationDocumentType;
use App\Services\VerificationDocumentTypes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * An admin configures which documents providers are asked for, from the dashboard: each type is mandatory or optional and can be switched off.
 * Applying as a provider needs a file for every ACTIVE MANDATORY type, so what is configured here is what the application form asks for.
 */
class DocumentTypeAdminTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private User $admin;

    private VerificationDocumentTypes $types;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeAdmin();
        $this->types = app(VerificationDocumentTypes::class);
    }

    private function as(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function create(array $over = [])
    {
        return $this->as($this->admin)->post(route('admin.document-types.store'), $over + ['key' => 'boat_registration', 'title_en' => 'Boat registration', 'title_ar' => 'تسجيل القارب', 'is_active' => '1']);
    }

    private function type(string $key): VerificationDocumentType
    {
        return VerificationDocumentType::where('key', $key)->firstOrFail();
    }

    // --- What ships ---------------------------------------------------------------------------------------------------

    public function test_the_platform_ships_with_eight_types_and_the_same_three_mandatory(): void
    {
        $this->assertSame(8, VerificationDocumentType::count());
        $this->assertEqualsCanonicalizing(['commercial_registration', 'safety_certification', 'insurance_certificate'], $this->types->mandatoryKeys());
        $this->assertSame(['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license', 'identity_document', 'professional_certification', 'insurance', 'other'], $this->types->keys(), 'In the order they are asked for');
    }

    public function test_every_type_has_a_name_in_both_languages(): void
    {
        foreach (VerificationDocumentType::all() as $type) {
            $this->assertNotSame('', $type->title_en);
            $this->assertNotSame('', $type->title_ar);
            $this->assertSame($type->title_ar, $type->title('ar'));
            $this->assertSame($type->title_en, $type->title('en'));
            $this->assertSame($type->title_en, $type->title('fr'), 'Any other language gets English');
        }
    }

    // --- Who may ---------------------------------------------------------------------------------------------------

    public function test_only_staff_with_the_permission_may_manage_document_types(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $this->as($this->makeAdmin($role))->get(route('admin.document-types.index'))->assertOk();
        }

        foreach (['content_admin', 'support_admin'] as $role) {
            $staff = $this->makeAdmin($role);
            $this->as($staff)->get(route('admin.document-types.index'))->assertForbidden();
            $this->as($staff)->get(route('admin.document-types.create'))->assertForbidden();
            $this->create();   // as the real admin, to have something to try on
            $type = $this->type('boat_registration');
            $this->as($staff)->put(route('admin.document-types.update', $type), ['title_en' => 'X', 'title_ar' => 'س'])->assertForbidden();
            $this->as($staff)->delete(route('admin.document-types.destroy', $type))->assertForbidden();
            $this->assertSame('Boat registration', $type->fresh()->title_en);
            $type->delete();
        }
    }

    public function test_a_provider_and_a_customer_are_sent_to_the_login_page(): void
    {
        [$providerUser] = $this->makeProvider('verified');

        $this->as($providerUser)->get(route('admin.document-types.index'))->assertRedirect(route('admin.login'));
        $this->as(User::factory()->customer()->create())->post(route('admin.document-types.store'), ['key' => 'x_doc', 'title_en' => 'X', 'title_ar' => 'س'])->assertRedirect(route('admin.login'));
        $this->assertNull(VerificationDocumentType::where('key', 'x_doc')->first());
    }

    // --- The list --------------------------------------------------------------------------------------------------

    public function test_the_list_shows_every_type_with_its_requirement_and_how_many_documents_use_it(): void
    {
        [, $provider] = $this->makeProvider('verified');
        ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'safety_certification', 'original_name' => 's.pdf', 'path' => 'p/s.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'approved']);

        $page = $this->as($this->admin)->get(route('admin.document-types.index'))->assertOk();

        $page->assertSee(__('admin.nav.document_types'))->assertSee('Commercial registration')->assertSee('commercial_registration')
            ->assertSee(__('admin.document_types.mandatory'))->assertSee(__('admin.document_types.optional'));
        $content = $page->getContent();
        $this->assertStringNotContainsString('action="'.route('admin.document-types.destroy', $this->type('safety_certification')).'"', $content, 'A type documents use has no delete button');
        $this->assertStringContainsString('action="'.route('admin.document-types.destroy', $this->type('other')).'"', $content, 'An unused one has');
    }

    // --- Adding a type -----------------------------------------------------------------------------------------------

    public function test_an_admin_adds_a_document_type(): void
    {
        $this->create(['description_en' => 'The registration of the boat', 'is_mandatory' => '1', 'sort_order' => 20])->assertRedirect(route('admin.document-types.index'))->assertSessionHas('status', __('admin.document_types.created'));

        $type = $this->type('boat_registration');
        $this->assertSame(['Boat registration', 'تسجيل القارب', true, true, 20], [$type->title_en, $type->title_ar, $type->is_mandatory, $type->is_active, $type->sort_order]);
        $this->assertContains('boat_registration', $this->types->keys());
        $this->assertContains('boat_registration', $this->types->mandatoryKeys());
    }

    public function test_a_new_type_is_optional_unless_marked_mandatory(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertFalse($this->type('boat_registration')->is_mandatory);
        $this->assertNotContains('boat_registration', $this->types->mandatoryKeys());
    }

    public function test_the_key_must_be_a_short_lower_case_word_and_unique(): void
    {
        foreach (['Boat', 'boat registration', '1boat', 'b', 'boat-registration', str_repeat('a', 41), '', 'قارب'] as $bad) {
            $this->create(['key' => $bad])->assertSessionHasErrors('key');
        }
        $this->assertSame(8, VerificationDocumentType::count());

        $this->create(['key' => 'commercial_registration'])->assertSessionHasErrors('key');
        $this->assertSame(8, VerificationDocumentType::count());
    }

    public function test_both_names_are_required(): void
    {
        $this->create(['title_en' => ''])->assertSessionHasErrors('title_en');
        $this->create(['title_ar' => ''])->assertSessionHasErrors('title_ar');
        $this->create(['sort_order' => -1])->assertSessionHasErrors('sort_order');
        $this->create(['sort_order' => 'soon'])->assertSessionHasErrors('sort_order');
        $this->assertSame(8, VerificationDocumentType::count());
    }

    // --- Editing -----------------------------------------------------------------------------------------------------

    public function test_a_type_can_be_renamed_made_mandatory_or_optional_and_switched_off(): void
    {
        $type = $this->type('business_license');

        $this->as($this->admin)->put(route('admin.document-types.update', $type), ['title_en' => 'Trade licence', 'title_ar' => 'رخصة تجارية', 'is_mandatory' => '1', 'is_active' => '1'])
            ->assertRedirect(route('admin.document-types.index'))->assertSessionHas('status', __('admin.document_types.updated'));
        $this->assertSame(['Trade licence', true], [$type->fresh()->title_en, $type->fresh()->is_mandatory]);
        $this->assertContains('business_license', $this->types->mandatoryKeys());

        $this->as($this->admin)->put(route('admin.document-types.update', $type), ['title_en' => 'Trade licence', 'title_ar' => 'رخصة تجارية', 'is_active' => '1']);
        $this->assertNotContains('business_license', $this->types->mandatoryKeys(), 'No checkbox ticked means optional');

        $this->as($this->admin)->put(route('admin.document-types.update', $type), ['title_en' => 'Trade licence', 'title_ar' => 'رخصة تجارية', 'is_mandatory' => '1']);
        $this->assertNotContains('business_license', $this->types->keys(), 'Switched off: not asked for at all');
        $this->assertNotContains('business_license', $this->types->mandatoryKeys(), 'A switched-off type is never mandatory, whatever its flag says');
    }

    public function test_the_key_can_never_be_changed(): void
    {
        $type = $this->type('business_license');

        $this->as($this->admin)->put(route('admin.document-types.update', $type), ['key' => 'renamed', 'title_en' => 'X', 'title_ar' => 'س'])->assertSessionHasErrors('key');

        $this->assertSame('business_license', $type->fresh()->key);
    }

    public function test_the_edit_form_shows_the_key_but_does_not_let_it_be_edited(): void
    {
        $page = $this->as($this->admin)->get(route('admin.document-types.edit', $this->type('business_license')))->assertOk();

        $page->assertSee('business_license')->assertSee(__('admin.document_types.key_fixed'))->assertDontSee('name="key"', false);
    }

    // --- Deleting ------------------------------------------------------------------------------------------------------

    public function test_a_type_nobody_used_can_be_deleted(): void
    {
        $this->create();
        $type = $this->type('boat_registration');

        $this->as($this->admin)->delete(route('admin.document-types.destroy', $type))->assertSessionHas('status', __('admin.document_types.deleted'));

        $this->assertNull(VerificationDocumentType::where('key', 'boat_registration')->first());
    }

    public function test_a_type_that_documents_use_is_switched_off_never_deleted(): void
    {
        [, $provider] = $this->makeProvider('verified');
        ProviderDocument::create(['provider_id' => $provider->id, 'type' => 'business_license', 'original_name' => 'l.pdf', 'path' => 'p/l.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'approved']);

        $this->as($this->admin)->delete(route('admin.document-types.destroy', $this->type('business_license')))->assertSessionHas('error', __('admin.document_types.in_use'));

        $this->assertNotNull(VerificationDocumentType::where('key', 'business_license')->first());
    }

    public function test_a_type_with_history_but_no_file_left_cannot_be_deleted_either(): void
    {
        [, $provider] = $this->makeProvider('verified');
        ProviderDocumentEvent::create(['provider_id' => $provider->id, 'document_type' => 'insurance', 'action' => 'update_requested', 'note' => 'Please send it', 'created_at' => now()]);

        $this->as($this->admin)->delete(route('admin.document-types.destroy', $this->type('insurance')))->assertSessionHas('error');

        $this->assertNotNull(VerificationDocumentType::where('key', 'insurance')->first());
    }

    // --- What it does to applying as a provider --------------------------------------------------------------------

    private function apply(array $p, array $documents)
    {
        return $this->hitWithFiles($p['token'], '/api/v1/provider/apply', array_merge($this->providerRequestPayload(), [
            'business_type' => 'Diving center', 'category_ids' => [$this->makeCategory('Diving'.uniqid())->id], 'marina_name' => 'Obhur Marina', 'phone' => '+966551112233', 'email' => 'a@b.sa',
        ], $documents));
    }

    private function pdf(string $name = 'x.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 30, 'application/pdf');
    }

    public function test_a_type_made_mandatory_is_required_to_apply(): void
    {
        $this->type('business_license')->update(['is_mandatory' => true]);

        $without = $this->unapprovedProvider('rejected');
        $this->apply($without, [])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('business_license');

        $with = $this->unapprovedProvider('rejected');
        $this->apply($with, ['business_license' => $this->pdf('licence.pdf')])->assertSuccessful();
        $this->assertTrue($with['provider']->documents()->where('type', 'business_license')->exists());
    }

    public function test_a_type_made_optional_or_switched_off_is_no_longer_required(): void
    {
        $this->type('safety_certification')->update(['is_mandatory' => false]);
        $this->type('insurance_certificate')->update(['is_active' => false]);

        $p = $this->unapprovedProvider('rejected');
        $this->apply($p, ['safety_certification' => null, 'insurance_certificate' => null])->assertSuccessful();
    }

    public function test_a_type_an_admin_added_is_asked_for_by_its_key(): void
    {
        $this->create(['is_mandatory' => '1'])->assertSessionHasNoErrors();

        $this->apply($this->unapprovedProvider('rejected'), [])->assertStatus(422)->assertJsonValidationErrors('boat_registration');

        $p = $this->unapprovedProvider('rejected');
        $this->apply($p, ['boat_registration' => $this->pdf('boat.pdf')])->assertSuccessful();
        $this->assertSame('boat.pdf', $p['provider']->documents()->where('type', 'boat_registration')->first()->original_name);
    }

    // --- Both languages ---------------------------------------------------------------------------------------------

    public function test_every_text_exists_in_both_languages(): void
    {
        $flatten = function (array $a, string $prefix = '') use (&$flatten) {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $out += $flatten($v, "{$prefix}{$k}.") : $out["{$prefix}{$k}"] = $v;
            }

            return $out;
        };

        foreach (['document_types', 'verification_documents'] as $group) {
            $en = $flatten(trans("admin.{$group}", [], 'en'));
            $ar = $flatten(trans("admin.{$group}", [], 'ar'));

            $this->assertGreaterThan(15, count($en), $group);
            $this->assertSame([], array_diff(array_keys($en), array_keys($ar)), "{$group}: missing in Arabic");
            $this->assertSame([], array_diff(array_keys($ar), array_keys($en)), "{$group}: missing in English");
        }
        foreach (['verification_documents', 'document_types'] as $nav) {
            $this->assertNotSame(trans("admin.nav.{$nav}", [], 'en'), trans("admin.nav.{$nav}", [], 'ar'));
        }
    }
}
