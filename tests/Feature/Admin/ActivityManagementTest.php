<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\ActivityImage;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\Provider;
use App\Models\Reservation;
use App\Notifications\Activities\ActivitySentBackToReviewNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Staff create, edit, delete and restore of activities, with the permission each needs.
 * A content admin may write listings (activities.create/update/delete) but not release
 * them (approve, reject, publish): that boundary is part of what is tested here.
 */
class ActivityManagementTest extends TestCase
{
    use AssertsTranslated;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    private function asAdmin(string $role = 'super_admin'): static
    {
        return $this->actingAs($this->makeAdmin($role), 'web');
    }

    private function validPayload(Provider $provider, Category $category, array $overrides = []): array
    {
        return array_merge([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Sunrise Reef Dive',
            'description' => 'A calm morning dive.',
            'location' => 'Hurghada',
            'meeting_point' => 'Marina dock 3',
            'lat' => 27.2,
            'lng' => 33.8,
            'duration_minutes' => 120,
            'min_participants' => 1,
            'max_participants' => 8,
            'min_age' => 12,
        ], $overrides);
    }

    /** A category with one attribute of every type the form can render. */
    private function categoryWithFeatures(): Category
    {
        $category = $this->makeCategory();
        $rows = [
            ['key' => 'dive_depth', 'label' => 'Dive Depth', 'type' => 'number', 'is_required' => true, 'sort_order' => 1],
            ['key' => 'certification_required', 'label' => 'Certification Required', 'type' => 'boolean', 'is_required' => false, 'sort_order' => 2],
            ['key' => 'boat_type', 'label' => 'Boat Type', 'type' => 'select', 'options' => [['key' => 'yacht', 'label' => 'Yacht'], ['key' => 'speedboat', 'label' => 'Speedboat']], 'is_required' => false, 'sort_order' => 3],
            ['key' => 'equipment_included', 'label' => 'Equipment Included', 'type' => 'multi_select', 'options' => [['key' => 'bcd', 'label' => 'BCD'], ['key' => 'regulator', 'label' => 'Regulator']], 'is_required' => false, 'sort_order' => 4],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text', 'is_required' => false, 'sort_order' => 5],
        ];
        foreach ($rows as $row) {
            FeatureDefinition::create($row + ['category_id' => $category->id]);
        }

        return $category;
    }

    private function image(Activity $activity, bool $cover = false, ?string $path = null): ActivityImage
    {
        $path ??= 'activities/'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'x');

        return ActivityImage::create(['activity_id' => $activity->id, 'path' => $path, 'sort_order' => 1, 'is_cover' => $cover]);
    }

    // --- Who may do what ---------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function permissionMatrix(): array
    {
        $actions = ['index', 'show', 'create', 'store', 'edit', 'update', 'features', 'requirements', 'inclusions', 'images_store', 'images_cover', 'images_destroy', 'destroy', 'restore', 'approve'];
        $everything = $actions;
        $writeButNotRelease = array_values(array_diff($actions, ['approve']));
        $allowed = [
            'super_admin' => $everything,
            'admin' => $everything,
            'content_admin' => $writeButNotRelease,
            'support_admin' => [],
            'none' => [],
        ];
        $cases = [];

        foreach ($allowed as $role => $may) {
            foreach ($actions as $action) {
                $cases["{$role} / {$action}"] = [$role, $action, in_array($action, $may, true) ? 'ok' : 'denied'];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionMatrix')]
    public function test_each_action_needs_its_permission(string $role, string $action, string $expected): void
    {
        $actor = $this->makeAdmin('super_admin');
        $actor->syncRoles($role === 'none' ? [] : [$role]);
        [, $provider] = $this->makeProvider('verified');
        $category = $this->categoryWithFeatures();
        $activity = $this->makeActivity($provider, $action === 'approve' ? 'pending_review' : 'draft', ['category' => $category]);
        $image = $this->image($activity, true);

        if ($action === 'restore') {
            $activity->delete();
        }

        $client = $this->actingAs($actor, 'web');
        $response = match ($action) {
            'index' => $client->get(route('admin.activities.index')),
            'show' => $client->get(route('admin.activities.show', $activity)),
            'create' => $client->get(route('admin.activities.create')),
            'store' => $client->post(route('admin.activities.store'), $this->validPayload($provider, $category)),
            'edit' => $client->get(route('admin.activities.edit', $activity)),
            'update' => $client->put(route('admin.activities.update', $activity), $this->validPayload($provider, $category, ['title' => 'Renamed'])),
            'features' => $client->put(route('admin.activities.features', $activity), ['features' => ['dive_depth' => 10]]),
            'requirements' => $client->put(route('admin.activities.requirements', $activity), ['requirements' => [['text' => 'Bring a towel']]]),
            'inclusions' => $client->put(route('admin.activities.inclusions', $activity), ['inclusions' => [['type' => 'included', 'description' => 'Lunch']]]),
            'images_store' => $client->post(route('admin.activities.images.store', $activity), ['images' => [UploadedFile::fake()->image('new.jpg')]]),
            'images_cover' => $client->put(route('admin.activities.images.cover', [$activity, $image])),
            'images_destroy' => $client->delete(route('admin.activities.images.destroy', [$activity, $image])),
            'destroy' => $client->delete(route('admin.activities.destroy', $activity)),
            'restore' => $client->post(route('admin.activities.restore', $activity)),
            'approve' => $client->post(route('admin.activities.approve', $activity)),
        };

        if ($expected === 'denied') {
            $this->assertContains($response->getStatusCode(), [302, 403], "{$role} must not be able to {$action}");
            if ($role !== 'none') {
                $response->assertForbidden();
            }
        } else {
            $this->assertContains($response->getStatusCode(), [200, 302], "{$role} should be able to {$action}");
            $this->assertNotSame(403, $response->getStatusCode());
        }
    }

    public function test_a_content_admin_can_write_a_listing_but_cannot_release_it(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $content = $this->makeAdmin('content_admin');

        $this->actingAs($content, 'web')->post(route('admin.activities.store'), $this->validPayload($provider, $category))->assertRedirect();
        $activity = Activity::firstOrFail();

        $this->actingAs($content, 'web')->post(route('admin.activities.approve', $activity))->assertForbidden();
        $this->actingAs($content, 'web')->post(route('admin.activities.publish', $activity))->assertForbidden();
        $this->assertSame('draft', $activity->fresh()->status);
    }

    public function test_a_denied_change_really_changes_nothing(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft', ['title' => 'Untouched']);
        $support = $this->makeAdmin('support_admin');

        $this->actingAs($support, 'web')->put(route('admin.activities.update', $activity), $this->validPayload($provider, $this->makeCategory(), ['title' => 'Hacked']))->assertForbidden();
        $this->actingAs($support, 'web')->delete(route('admin.activities.destroy', $activity))->assertForbidden();

        $this->assertSame('Untouched', $activity->fresh()->title);
        $this->assertNull($activity->fresh()->deleted_at);
    }

    // --- The list ----------------------------------------------------------------

    public function test_the_list_can_be_searched_by_title_or_provider_and_shows_deleted_on_request(): void
    {
        [, $p1] = $this->makeProvider('verified');
        $p1->update(['business_name' => 'Coral Divers']);
        [, $p2] = $this->makeProvider('verified');
        $p2->update(['business_name' => 'Reef Riders']);
        $this->makeActivity($p1, 'draft', ['title' => 'Night Dive']);
        $this->makeActivity($p2, 'draft', ['title' => 'Day Cruise']);
        $gone = $this->makeActivity($p2, 'draft', ['title' => 'Removed Trip']);
        $gone->delete();
        $admin = $this->makeAdmin();
        $get = fn (array $q) => $this->actingAs($admin, 'web')->get(route('admin.activities.index', $q));

        $get(['search' => 'Night'])->assertSee('Night Dive')->assertDontSee('Day Cruise');
        $get(['search' => 'Reef Riders'])->assertSee('Day Cruise')->assertDontSee('Night Dive');
        $get([])->assertDontSee('Removed Trip');
        $get(['trashed' => 'only'])->assertSee('Removed Trip')->assertDontSee('Night Dive');
    }

    public function test_the_list_buttons_follow_the_permissions_and_a_deleted_row_offers_restore_only(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $live = $this->makeActivity($provider, 'draft', ['title' => 'Live Row']);
        $gone = $this->makeActivity($provider, 'draft', ['title' => 'Gone Row']);
        $gone->delete();

        $super = $this->actingAs($this->makeAdmin('super_admin'), 'web');
        $page = $super->get(route('admin.activities.index'));
        $page->assertSee(route('admin.activities.edit', $live))->assertSee(route('admin.activities.create'))->assertSee('value="DELETE"', false);

        $deletedView = $this->actingAs($this->makeAdmin('super_admin'), 'web')->get(route('admin.activities.index', ['trashed' => 'only']));
        $deletedView->assertSee(route('admin.activities.restore', $gone))->assertDontSee(route('admin.activities.edit', $gone))->assertDontSee('value="DELETE"', false);

        // A role that may not see activities at all gets no page.
        $this->actingAs($this->makeAdmin('support_admin'), 'web')->get(route('admin.activities.index'))->assertForbidden();
    }

    public function test_the_list_links_to_the_detail_page(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asAdmin()->get(route('admin.activities.index'))->assertSee(route('admin.activities.show', $activity));
    }

    // --- Creating ----------------------------------------------------------------

    public function test_staff_can_create_an_activity_for_a_provider_and_it_starts_as_a_draft(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $category = $this->makeCategory();

        $response = $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($provider, $category));

        $activity = Activity::firstOrFail();
        $response->assertRedirect(route('admin.activities.edit', $activity));
        $this->assertSame($provider->id, $activity->provider_id);
        $this->assertSame('draft', $activity->status);
        $this->assertSame('sunrise-reef-dive', $activity->slug);
        $this->assertSame('Sunrise Reef Dive', $activity->title);
        $this->assertSame(12, $activity->min_age);
        $this->assertSame(8, $activity->max_participants);
    }

    public function test_the_status_cannot_be_chosen_when_creating(): void
    {
        [, $provider] = $this->makeProvider('verified');

        $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($provider, $this->makeCategory(), ['status' => 'published', 'published_at' => now()->toDateTimeString(), 'average_rating' => 5]));

        $activity = Activity::firstOrFail();
        $this->assertSame('draft', $activity->status, 'Releasing an activity is approve/publish, with their permission and gate.');
        $this->assertNull($activity->published_at);
    }

    public function test_an_empty_minimum_uses_the_default_of_one(): void
    {
        [, $provider] = $this->makeProvider('verified');

        $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($provider, $this->makeCategory(), ['min_participants' => '']));

        $this->assertSame(1, Activity::firstOrFail()->min_participants);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'no title' => [['title' => ''], 'title'],
            'no category' => [['category_id' => ''], 'category_id'],
            'no provider' => [['provider_id' => ''], 'provider_id'],
            'a category that does not exist' => [['category_id' => 99999], 'category_id'],
            'a provider that does not exist' => [['provider_id' => 99999], 'provider_id'],
            'latitude out of range' => [['lat' => 95], 'lat'],
            'longitude out of range' => [['lng' => 200], 'lng'],
            'a zero duration' => [['duration_minutes' => 0], 'duration_minutes'],
            'a maximum below the minimum' => [['min_participants' => 5, 'max_participants' => 2], 'max_participants'],
            'a minimum age over 120' => [['min_age' => 121], 'min_age'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_and_nothing_is_created(array $overrides, string $field): void
    {
        [, $provider] = $this->makeProvider('verified');

        $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($provider, $this->makeCategory(), $overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Activity::count());
    }

    public function test_an_inactive_category_cannot_be_chosen(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $category->update(['status' => 'inactive']);

        $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($provider, $category))->assertSessionHasErrors('category_id');
    }

    public function test_an_activity_cannot_be_created_for_a_deleted_or_suspended_provider(): void
    {
        [$deletedUser, $deleted] = $this->makeProvider('verified');
        $deletedUser->delete();
        [$suspendedUser, $suspended] = $this->makeProvider('verified');
        $suspendedUser->update(['status' => 'suspended']);
        $category = $this->makeCategory();

        $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($deleted, $category))->assertSessionHasErrors('provider_id');
        $this->asAdmin()->post(route('admin.activities.store'), $this->validPayload($suspended, $category))->assertSessionHasErrors('provider_id');
        $this->assertSame(0, Activity::count());
    }

    public function test_the_provider_dropdown_offers_only_providers_who_can_own_an_activity(): void
    {
        [, $ok] = $this->makeProvider('verified');
        $ok->update(['business_name' => 'Available Diving']);
        [$deletedUser, $deleted] = $this->makeProvider('verified');
        $deleted->update(['business_name' => 'Deleted Diving']);
        $deletedUser->delete();
        [$suspendedUser, $suspended] = $this->makeProvider('verified');
        $suspended->update(['business_name' => 'Suspended Diving']);
        $suspendedUser->update(['status' => 'suspended']);

        $this->asAdmin()->get(route('admin.activities.create'))
            ->assertSee('Available Diving')->assertDontSee('Deleted Diving')->assertDontSee('Suspended Diving');
    }

    // --- Editing -----------------------------------------------------------------

    public function test_staff_can_edit_the_core_details(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $activity = $this->makeActivity($provider, 'draft', ['category' => $category]);

        $this->asAdmin()->put(route('admin.activities.update', $activity), $this->validPayload($provider, $category, [
            'title' => 'Completely New Title', 'location' => 'Dahab', 'max_participants' => 20, 'min_age' => 8,
        ]))->assertRedirect(route('admin.activities.edit', $activity));

        $activity->refresh();
        $this->assertSame('Completely New Title', $activity->title);
        $this->assertSame('completely-new-title', $activity->slug);
        $this->assertSame('Dahab', $activity->location);
        $this->assertSame(20, $activity->max_participants);
    }

    public function test_the_provider_of_an_activity_cannot_be_changed_by_editing(): void
    {
        [, $owner] = $this->makeProvider('verified');
        [, $other] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $activity = $this->makeActivity($owner, 'draft', ['category' => $category]);

        $this->asAdmin()->put(route('admin.activities.update', $activity), $this->validPayload($other, $category))->assertSessionHasNoErrors();

        $this->assertSame($owner->id, $activity->fresh()->provider_id);
    }

    public function test_a_staff_edit_does_not_send_a_published_activity_back_to_review(): void
    {
        [$providerUser, $provider] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $activity = $this->makeActivity($provider, 'published', ['category' => $category]);

        // The very change that sends a provider's own edit back to review.
        $this->asAdmin('content_admin')->put(route('admin.activities.update', $activity), $this->validPayload($provider, $category, ['title' => 'Edited By Staff']))->assertSessionHasNoErrors();

        $this->assertSame('published', $activity->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $providerUser->id)->where('type', ActivitySentBackToReviewNotification::class)->count());
        $this->getJson("/api/v1/public/activities/{$activity->fresh()->slug}")->assertOk();
    }

    public function test_changing_the_category_clears_the_features_of_the_old_one(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $old = $this->categoryWithFeatures();
        $new = $this->makeCategory('Snorkeling');
        $activity = $this->makeActivity($provider, 'draft', ['category' => $old]);
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->put(route('admin.activities.features', $activity), ['features' => ['dive_depth' => 18]]);
        $this->assertGreaterThan(0, $activity->featureValues()->count());

        $this->actingAs($admin, 'web')->put(route('admin.activities.update', $activity), $this->validPayload($provider, $new));

        $this->assertSame(0, $activity->featureValues()->count());
    }

    public function test_a_deleted_activity_cannot_be_edited(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $activity->delete();

        $this->asAdmin()->get(route('admin.activities.edit', $activity))->assertNotFound();
        $this->asAdmin()->put(route('admin.activities.update', $activity), $this->validPayload($provider, $this->makeCategory()))->assertNotFound();
    }

    // --- Features ----------------------------------------------------------------

    public function test_the_edit_page_shows_an_input_for_every_attribute_of_the_category(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft', ['category' => $this->categoryWithFeatures()]);

        $page = $this->asAdmin()->get(route('admin.activities.edit', $activity));

        $page->assertOk()
            ->assertSee('name="features[dive_depth]"', false)
            ->assertSee('name="features[certification_required]"', false)
            ->assertSee('name="features[boat_type]"', false)
            ->assertSee('name="features[equipment_included][]"', false)
            ->assertSee('name="features[notes]"', false);
    }

    public function test_features_of_every_type_can_be_saved_and_shown_again(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft', ['category' => $this->categoryWithFeatures()]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->put(route('admin.activities.features', $activity), ['features' => [
            'dive_depth' => 18, 'certification_required' => 1, 'boat_type' => 'speedboat', 'equipment_included' => ['bcd', 'regulator'], 'notes' => 'Bring a hat',
        ]])->assertSessionHasNoErrors();

        $values = $activity->featureValues()->with('featureDefinition')->get()->mapWithKeys(fn ($v) => [$v->featureDefinition->key => $v->value])->all();
        $this->assertEquals(18, $values['dive_depth']);
        $this->assertTrue($values['certification_required']);
        $this->assertSame('speedboat', $values['boat_type']);
        $this->assertSame(['bcd', 'regulator'], $values['equipment_included']);
        $this->assertSame('Bring a hat', $values['notes']);

        $this->actingAs($admin, 'web')->get(route('admin.activities.edit', $activity))->assertSee('value="18"', false)->assertSee('Bring a hat');
    }

    public function test_an_unticked_checkbox_and_an_emptied_multi_select_are_saved_as_cleared(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft', ['category' => $this->categoryWithFeatures()]);
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->put(route('admin.activities.features', $activity), ['features' => ['dive_depth' => 10, 'certification_required' => 1, 'equipment_included' => ['bcd']]]);

        // The browser sends the hidden 0 for an unticked box and nothing at all for an empty multi-select.
        $this->actingAs($admin, 'web')->put(route('admin.activities.features', $activity), ['features' => ['dive_depth' => 10, 'certification_required' => 0]])->assertSessionHasNoErrors();

        $values = $activity->featureValues()->with('featureDefinition')->get()->mapWithKeys(fn ($v) => [$v->featureDefinition->key => $v->value])->all();
        $this->assertFalse($values['certification_required']);
        $this->assertSame([], $values['equipment_included']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidFeatures(): array
    {
        return [
            'a required attribute left empty' => [['dive_depth' => ''], 'features.dive_depth'],
            'text where a number is expected' => [['dive_depth' => 'deep'], 'features.dive_depth'],
            'a choice that is not an option' => [['dive_depth' => 5, 'boat_type' => 'submarine'], 'features.boat_type'],
            'a multi-select choice that is not an option' => [['dive_depth' => 5, 'equipment_included' => ['wings']], 'features.equipment_included.0'],
            'an attribute from another category' => [['dive_depth' => 5, 'bogus' => 'x'], 'features.bogus'],
        ];
    }

    #[DataProvider('invalidFeatures')]
    public function test_invalid_features_are_rejected_without_a_server_error(array $features, string $errorKey): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft', ['category' => $this->categoryWithFeatures()]);

        $this->asAdmin()->put(route('admin.activities.features', $activity), ['features' => $features])->assertSessionHasErrors($errorKey);

        $this->assertSame(0, $activity->featureValues()->count());
    }

    // --- Requirements and inclusions ----------------------------------------------

    public function test_requirements_are_saved_in_order_and_empty_rows_are_ignored(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asAdmin()->put(route('admin.activities.requirements', $activity), ['requirements' => [
            ['text' => 'Valid certification', 'is_mandatory' => 1],
            ['text' => '', 'is_mandatory' => 0],
            ['text' => 'Bring a towel', 'is_mandatory' => 0],
            ['text' => '   ', 'is_mandatory' => 1],
        ]])->assertSessionHasNoErrors();

        $rows = $activity->requirements()->get();
        $this->assertSame(['Valid certification', 'Bring a towel'], $rows->pluck('text')->all());
        $this->assertSame([true, false], $rows->map(fn ($r) => (bool) $r->is_mandatory)->all());
    }

    public function test_saving_replaces_the_old_requirements_and_clearing_every_row_removes_them(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->put(route('admin.activities.requirements', $activity), ['requirements' => [['text' => 'First'], ['text' => 'Second']]]);

        $this->actingAs($admin, 'web')->put(route('admin.activities.requirements', $activity), ['requirements' => [['text' => 'Only one']]]);
        $this->assertSame(['Only one'], $activity->requirements()->pluck('text')->all());

        $this->actingAs($admin, 'web')->put(route('admin.activities.requirements', $activity), ['requirements' => [['text' => ''], ['text' => '']]])->assertSessionHasNoErrors();
        $this->assertSame(0, $activity->requirements()->count());
    }

    public function test_a_requirement_that_is_too_long_is_rejected(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asAdmin()->put(route('admin.activities.requirements', $activity), ['requirements' => [['text' => str_repeat('x', 256)]]])->assertSessionHasErrors('requirements.0.text');
    }

    public function test_inclusions_and_exclusions_can_be_saved_and_cleared(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->put(route('admin.activities.inclusions', $activity), ['inclusions' => [
            ['type' => 'included', 'description' => 'Equipment'],
            ['type' => 'excluded', 'description' => 'Lunch'],
            ['type' => 'included', 'description' => ''],
        ]])->assertSessionHasNoErrors();

        $rows = $activity->inclusions()->get();
        $this->assertSame([['included', 'Equipment'], ['excluded', 'Lunch']], $rows->map(fn ($r) => [$r->type, $r->description])->all());

        $this->actingAs($admin, 'web')->put(route('admin.activities.inclusions', $activity), ['inclusions' => [['type' => 'included', 'description' => '']]])->assertSessionHasNoErrors();
        $this->assertSame(0, $activity->inclusions()->count());
    }

    public function test_an_inclusion_with_an_unknown_type_is_rejected(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asAdmin()->put(route('admin.activities.inclusions', $activity), ['inclusions' => [['type' => 'bonus', 'description' => 'Free gift']]])->assertSessionHasErrors('inclusions.0.type');
    }

    // --- Photos ------------------------------------------------------------------

    public function test_photos_can_be_uploaded_and_the_first_becomes_the_cover(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asAdmin()->post(route('admin.activities.images.store', $activity), ['images' => [
            UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.webp'),
        ]])->assertSessionHasNoErrors();

        $images = $activity->images()->orderBy('sort_order')->get();
        $this->assertCount(3, $images);
        $this->assertSame([true, false, false], $images->map(fn ($i) => (bool) $i->is_cover)->all());
        foreach ($images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedUploads(): array
    {
        return [
            'an SVG, which can carry a script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            'an HTML page' => ['page.html', '<html><script>alert(1)</script></html>'],
            'a PHP file' => ['shell.php', '<?php system($_GET["c"]);'],
            'a PDF' => ['brochure.pdf', '%PDF-1.4'],
        ];
    }

    #[DataProvider('rejectedUploads')]
    public function test_only_photos_are_accepted(string $filename, string $content): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');

        $this->asAdmin()->post(route('admin.activities.images.store', $activity), ['images' => [UploadedFile::fake()->createWithContent($filename, $content)]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $activity->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_photo_limits_are_enforced(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.activities.images.store', $activity), ['images' => [UploadedFile::fake()->image('big.jpg')->size(4097)]])->assertSessionHasErrors('images.0');
        $this->actingAs($admin, 'web')->post(route('admin.activities.images.store', $activity), ['images' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11))])->assertSessionHasErrors('images');
        $this->actingAs($admin, 'web')->post(route('admin.activities.images.store', $activity), [])->assertSessionHasErrors('images');
        $this->assertSame(0, $activity->images()->count());
    }

    public function test_a_photo_can_be_made_the_cover_and_deleting_the_cover_promotes_another(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $first = $this->image($activity, true);
        $second = $this->image($activity, false);
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->put(route('admin.activities.images.cover', [$activity, $second]))->assertSessionHasNoErrors();
        $this->assertTrue((bool) $second->fresh()->is_cover);
        $this->assertFalse((bool) $first->fresh()->is_cover);

        $this->actingAs($admin, 'web')->delete(route('admin.activities.images.destroy', [$activity, $second]))->assertSessionHasNoErrors();
        $this->assertNull(ActivityImage::find($second->id));
        Storage::disk('public')->assertMissing($second->path);
        $this->assertTrue((bool) $first->fresh()->is_cover, 'An activity should never have photos but no cover.');
    }

    public function test_another_activitys_photo_cannot_be_touched_through_this_one(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $mine = $this->makeActivity($provider, 'draft');
        $theirs = $this->makeActivity($provider, 'draft');
        $foreign = $this->image($theirs, true);
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->delete(route('admin.activities.images.destroy', [$mine, $foreign]))->assertNotFound();
        $this->actingAs($admin, 'web')->put(route('admin.activities.images.cover', [$mine, $foreign]))->assertNotFound();

        $this->assertNotNull(ActivityImage::find($foreign->id));
        Storage::disk('public')->assertExists($foreign->path);
    }

    // --- Deleting and restoring --------------------------------------------------------

    public function test_deleting_soft_deletes_and_a_published_activity_is_deactivated_first(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');

        $this->asAdmin()->delete(route('admin.activities.destroy', $activity))->assertRedirect(route('admin.activities.index'));

        $this->assertSoftDeleted('activities', ['id' => $activity->id]);
        $this->assertSame('inactive', Activity::withTrashed()->find($activity->id)->status);
        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertNotFound();
    }

    public function test_a_deleted_activity_disappears_from_the_provider_and_the_default_list_and_can_be_restored_as_inactive(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published', ['title' => 'Bye Bye Dive']);
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->delete(route('admin.activities.destroy', $activity));

        $this->actingAs($admin, 'web')->get(route('admin.activities.index'))->assertDontSee('Bye Bye Dive');
        $this->actingAs($admin, 'web')->get(route('admin.activities.show', $activity))->assertOk()->assertSee(__('admin.activities.deleted_notice'));
        $this->app['auth']->forgetGuards();
        $mine = $this->asProvider($token)->getJson('/api/v1/provider/activities')->assertOk();
        $this->assertSame([], collect($mine->json('data'))->pluck('title')->intersect(['Bye Bye Dive'])->all());

        $this->actingAs($admin, 'web')->post(route('admin.activities.restore', $activity))->assertRedirect(route('admin.activities.show', $activity));

        $restored = $activity->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertSame('inactive', $restored->status, 'Restoring must never silently put it back on the public site.');
        $this->getJson("/api/v1/public/activities/{$restored->slug}")->assertNotFound();
    }

    /** @return array<string, array{0: string}> */
    public static function liveBookingStates(): array
    {
        return [
            'a confirmed booking still ahead' => ['confirmed_future'],
            'a confirmed booking for today' => ['confirmed_today'],
            'a pending booking whose hold is still running' => ['pending_hold'],
        ];
    }

    #[DataProvider('liveBookingStates')]
    public function test_a_live_booking_blocks_the_delete_and_explains_why(string $state): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option, $state === 'confirmed_today' ? Carbon::today() : null);
        if ($state !== 'pending_hold') {
            $this->payFor($reservation);
        }

        $this->asAdmin()->delete(route('admin.activities.destroy', $activity))->assertSessionHas('error');

        $this->assertStringContainsString('1 upcoming booking', session('error'));
        $fresh = Activity::withTrashed()->find($activity->id);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('published', $fresh->status, 'A blocked delete must change nothing.');
    }

    /** @return array<string, array{0: string}> */
    public static function historyStates(): array
    {
        return [
            'a cancelled booking' => ['cancelled'],
            'an expired hold' => ['expired'],
            'a completed booking' => ['completed'],
            'a confirmed booking that already took place' => ['confirmed_past'],
            'a pending booking whose hold ran out' => ['pending_lapsed'],
        ];
    }

    #[DataProvider('historyStates')]
    public function test_bookings_that_are_history_do_not_block_the_delete(string $state): void
    {
        [, $provider] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $reservation = $this->bookActivity($customer, $activity, $option);
        $this->payFor($reservation);

        match ($state) {
            'cancelled' => Reservation::whereKey($reservation->id)->update(['status' => 'cancelled']),
            'expired' => Reservation::whereKey($reservation->id)->update(['status' => 'expired']),
            'completed' => Reservation::whereKey($reservation->id)->update(['status' => 'completed']),
            'confirmed_past' => Reservation::whereKey($reservation->id)->update(['reservation_date' => Carbon::yesterday()->toDateString()]),
            'pending_lapsed' => Reservation::whereKey($reservation->id)->update(['status' => 'pending', 'hold_expires_at' => now()->subMinute()]),
        };

        $this->asAdmin()->delete(route('admin.activities.destroy', $activity))->assertSessionMissing('error');

        $this->assertSoftDeleted('activities', ['id' => $activity->id]);
    }

    public function test_the_provider_api_obeys_the_same_delete_rule(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        [$activity, $option] = $this->makeBookableActivity($provider);
        [, $customer] = $this->makeCustomer();
        $this->payFor($this->bookActivity($customer, $activity, $option));

        // Before this rule a provider could delete an activity that had a paid, upcoming booking.
        $this->asProvider($token)->deleteJson("/api/v1/provider/activities/{$activity->uuid}")
            ->assertStatus(422)->assertJsonValidationErrors(['delete']);
        $this->assertNull($activity->fresh()->deleted_at);
    }

    public function test_the_provider_api_can_delete_an_activity_with_no_live_booking_and_it_is_deactivated_first(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'published');

        $this->asProvider($token)->deleteJson("/api/v1/provider/activities/{$activity->uuid}")->assertOk();

        $this->assertSoftDeleted('activities', ['id' => $activity->id]);
        $this->assertSame('inactive', Activity::withTrashed()->find($activity->id)->status);
    }

    public function test_deleting_twice_or_restoring_a_live_activity_is_refused_cleanly(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, 'draft');
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.activities.restore', $activity))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'web')->delete(route('admin.activities.destroy', $activity))->assertRedirect();
        // The second delete targets an activity that is already gone: not found, not a server error.
        $this->actingAs($admin, 'web')->delete(route('admin.activities.destroy', $activity))->assertNotFound();
    }

    // --- Language ------------------------------------------------------------------------

    public function test_every_activity_screen_is_fully_translated_in_both_languages(): void
    {
        [, $provider] = $this->makeProvider('verified');
        $category = $this->categoryWithFeatures();
        $activity = $this->makeActivity($provider, 'draft', ['category' => $category]);
        $this->image($activity, true);
        $deleted = $this->makeActivity($provider, 'draft', ['title' => 'A Deleted One']);
        $deleted->delete();
        $admin = $this->makeAdmin();

        $this->inEachAdminLanguage(function (string $locale) use ($admin, $activity, $deleted) {
            $pages = [
                'index' => route('admin.activities.index'),
                'deleted list' => route('admin.activities.index', ['trashed' => 'only']),
                'create' => route('admin.activities.create'),
                'edit' => route('admin.activities.edit', $activity),
                'show' => route('admin.activities.show', $activity),
                'show (deleted)' => route('admin.activities.show', $deleted),
            ];
            foreach ($pages as $name => $url) {
                $response = $this->actingAs($admin, 'web')->get($url)->assertOk();
                $this->assertNoRawTranslationKeys($response, "({$name}, {$locale})");
            }
        });
    }
}
