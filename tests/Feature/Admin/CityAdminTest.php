<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\City;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\TestCase;

/** The admin's cities screen (the destinations of the customer app) and featuring an activity on its home screen. */
class CityAdminTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeAdmin();
    }

    private function as(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function png(string $name = 'jeddah.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function store(array $over = [], ?User $as = null)
    {
        return $this->as($as ?? $this->admin)->post(route('admin.cities.store'), $over + ['name_en' => 'Jeddah', 'name_ar' => 'جدة', 'is_active' => '1']);
    }

    // --- Cities -------------------------------------------------------------------------------------------------

    public function test_the_list_shows_every_city_with_its_activities_and_status(): void
    {
        $jeddah = $this->makeCity('Jeddah', 'جدة', ['sort_order' => 1]);
        $this->makeCity('Yanbu', 'ينبع', ['sort_order' => 2, 'is_active' => false]);
        $a = $this->approvedProvider();
        $a['activity']->update(['city_id' => $jeddah->id]);

        $page = $this->as($this->admin)->get(route('admin.cities.index'))->assertOk();

        $page->assertSeeInOrder(['Jeddah', 'Yanbu'])->assertSee(__('admin.cities.title'))->assertSee(__('admin.cities.inactive'))->assertSee('جدة');
        $page->assertSee(route('admin.cities.edit', $jeddah));
    }

    public function test_a_city_is_added_with_a_slug_made_from_its_english_name_and_a_new_one_is_free(): void
    {
        $this->store()->assertRedirect(route('admin.cities.index'))->assertSessionHas('status', __('admin.cities.created'));
        $this->store(['name_ar' => 'جدة 2'])->assertSessionHasNoErrors();

        $this->assertSame(['jeddah', 'jeddah-2'], City::orderBy('id')->pluck('slug')->all());
        $city = City::firstOrFail();
        $this->assertSame(['Jeddah', 'جدة', true, 0], [$city->name_en, $city->name_ar, $city->is_active, $city->sort_order]);
    }

    public function test_an_unticked_box_means_the_city_is_switched_off(): void
    {
        $this->as($this->admin)->post(route('admin.cities.store'), ['name_en' => 'Umluj', 'name_ar' => 'أملج'])->assertSessionHasNoErrors();

        $this->assertFalse(City::firstOrFail()->is_active);
    }

    public function test_the_fields_are_checked(): void
    {
        $this->makeCity('Taken', 'x', ['slug' => 'taken']);

        foreach ([['name_en' => ''], ['name_ar' => ''], ['name_en' => 'J'], ['slug' => 'Has Spaces'], ['slug' => 'taken'], ['sort_order' => -1], ['sort_order' => 'x'],
            ['image' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')],
            ['image' => UploadedFile::fake()->create('big.png', 3000, 'image/png')]] as $bad) {
            $this->store($bad)->assertSessionHasErrors();
        }

        $this->assertSame(1, City::count());
    }

    public function test_a_picture_is_stored_under_our_name_and_replaced_cleanly(): void
    {
        $this->store(['image' => $this->png()])->assertSessionHasNoErrors();
        $city = City::firstOrFail();
        $first = $city->image;

        $this->assertStringStartsWith('cities/', $first);
        $this->assertStringNotContainsString('jeddah.png', $first);
        Storage::disk('public')->assertExists($first);

        $this->as($this->admin)->put(route('admin.cities.update', $city), ['name_en' => 'Jeddah', 'name_ar' => 'جدة', 'is_active' => '1', 'image' => $this->png('new.png')])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($city->refresh()->image);
    }

    public function test_a_city_can_be_edited_and_keeps_its_slug_unless_changed(): void
    {
        $city = $this->makeCity('Jeddah', 'جدة', ['slug' => 'jeddah']);

        $this->as($this->admin)->put(route('admin.cities.update', $city), ['name_en' => 'Jeddah City', 'name_ar' => 'مدينة جدة', 'sort_order' => 3])->assertRedirect(route('admin.cities.index'))
            ->assertSessionHas('status', __('admin.cities.updated'));

        $city->refresh();
        $this->assertSame(['Jeddah City', 'مدينة جدة', 'jeddah', 3, false], [$city->name_en, $city->name_ar, $city->slug, $city->sort_order, $city->is_active]);
        $this->as($this->admin)->put(route('admin.cities.update', $city), ['name_en' => 'Jeddah City', 'name_ar' => 'x1', 'slug' => 'jeddah'])->assertSessionHasNoErrors();   // its own slug is not a clash
    }

    public function test_an_unused_city_is_deleted_with_its_picture_and_a_used_one_is_not(): void
    {
        $this->store(['image' => $this->png()]);
        $unused = City::firstOrFail();
        $path = $unused->image;
        $used = $this->makeCity('Yanbu', 'ينبع');
        $a = $this->approvedProvider();
        $a['activity']->update(['city_id' => $used->id]);

        $this->as($this->admin)->delete(route('admin.cities.destroy', $used))->assertSessionHasErrors('city');
        $this->assertNotNull(City::find($used->id));

        $this->as($this->admin)->delete(route('admin.cities.destroy', $unused))->assertSessionHas('status', __('admin.cities.deleted'));
        $this->assertNull(City::find($unused->id));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame($used->id, $a['activity']->refresh()->city_id);
    }

    public function test_only_staff_with_the_permission_may_manage_cities(): void
    {
        $city = $this->makeCity();

        foreach (['content_admin', 'support_admin'] as $role) {
            $staff = $this->makeAdmin($role);
            $this->as($staff)->get(route('admin.cities.index'))->assertForbidden();
            $this->store([], $staff)->assertForbidden();
            $this->as($staff)->put(route('admin.cities.update', $city), ['name_en' => 'Hacked', 'name_ar' => 'x1'])->assertForbidden();
            $this->as($staff)->delete(route('admin.cities.destroy', $city))->assertForbidden();
        }
        foreach (['admin', 'super_admin'] as $role) {
            $this->as($this->makeAdmin($role))->get(route('admin.cities.index'))->assertOk();
        }
        $this->assertSame('Jeddah', $city->refresh()->name_en);
        $this->assertSame(1, City::count());
    }

    public function test_a_provider_and_a_customer_cannot_reach_it(): void
    {
        $a = $this->approvedProvider();
        $this->as($a['user'])->get(route('admin.cities.index'))->assertRedirect(route('admin.login'));
        [$customer] = $this->makeCustomer();
        $this->as($customer)->get(route('admin.cities.index'))->assertRedirect(route('admin.login'));
    }

    public function test_the_menu_offers_cities_only_to_those_who_can_use_it(): void
    {
        $this->as($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.cities.index'));
        $this->as($this->makeAdmin('content_admin'))->get(route('admin.dashboard'))->assertDontSee(route('admin.cities.index'));
    }

    public function test_a_city_is_shown_to_customers_in_the_language_asked_for(): void
    {
        $city = $this->makeCity('Jeddah', 'جدة');
        $a = $this->approvedProvider();
        $a['activity']->update(['city_id' => $city->id]);

        $this->assertSame('Jeddah', $this->ca(null, 'GET', '/activities')->json('data.0.city'));
        $this->assertSame('جدة', $this->ca(null, 'GET', '/activities', [], ['Accept-Language' => 'ar'])->json('data.0.city'));
    }

    // --- Featuring an activity -------------------------------------------------------------------------------------------

    private function feature(Activity $activity, ?User $as = null)
    {
        return $this->as($as ?? $this->admin)->post(route('admin.activities.feature', $activity));
    }

    public function test_a_live_activity_is_featured_and_un_featured_and_the_home_screen_follows(): void
    {
        $a = $this->approvedProvider();

        $this->feature($a['activity'])->assertSessionHas('status', __('admin.activities.featured'));
        $this->assertTrue($a['activity']->refresh()->is_featured);
        $this->assertSame(['Reef Dive'], array_column($this->ca(null, 'GET', '/home')->json('data.featured'), 'title'));
        $this->assertTrue($this->ca(null, 'GET', '/activities')->json('data.0.is_premium'));

        $this->feature($a['activity'])->assertSessionHas('status', __('admin.activities.unfeatured'));
        $this->assertFalse($a['activity']->refresh()->is_featured);
        $this->assertSame([], $this->ca(null, 'GET', '/home')->json('data.featured'));
    }

    public function test_an_activity_that_is_not_live_cannot_be_featured_but_can_always_be_taken_off(): void
    {
        $a = $this->approvedProvider();

        foreach (['draft', 'inactive'] as $status) {
            $a['activity']->update(['status' => $status]);
            $this->feature($a['activity'])->assertSessionHasErrors('activity');
            $this->assertFalse($a['activity']->refresh()->is_featured, $status);
        }

        $a['activity']->update(['status' => 'published', 'suspended_at' => now(), 'suspension_reason' => 'x']);
        $this->feature($a['activity'])->assertSessionHasErrors('activity');

        $a['activity']->update(['is_featured' => true]);
        $this->feature($a['activity'])->assertSessionHasNoErrors();
        $this->assertFalse($a['activity']->refresh()->is_featured, 'Taking it off always works');
    }

    public function test_only_staff_with_the_permission_may_feature(): void
    {
        $a = $this->approvedProvider();

        $this->feature($a['activity'], $this->makeAdmin('content_admin'))->assertForbidden();
        $this->feature($a['activity'], $this->makeAdmin('support_admin'))->assertForbidden();
        $this->feature($a['activity'], $this->makeAdmin('admin'))->assertSessionHasNoErrors();

        $this->assertTrue($a['activity']->refresh()->is_featured);
    }

    public function test_the_activities_list_offers_the_right_button(): void
    {
        $a = $this->approvedProvider();

        $this->as($this->admin)->get(route('admin.activities.index'))->assertOk()->assertSee(route('admin.activities.feature', $a['activity']))->assertSee(__('admin.activities.feature'));

        $a['activity']->update(['is_featured' => true]);
        $this->as($this->admin)->get(route('admin.activities.index'))->assertSee(__('admin.activities.unfeature'));
    }
}
