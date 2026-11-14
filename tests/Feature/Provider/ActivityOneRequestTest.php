<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivityImage;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * Creating and changing an activity, photos and all, in ONE request. On create every part (photos, features, options, requirements, inclusions, schedule,
 * booking options with their prices) is optional and may be null. On update `new_images` adds photos and `deleted_image_ids` removes them; a part that is
 * null or missing is left alone, and an empty list clears it. Files are sent as a form upload; an update with files is a POST with `_method=PUT`.
 */
class ActivityOneRequestTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->a = $this->approvedProvider();
        $this->category = $this->a['activity']->category_id;
    }

    private function png(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function form(string $method, string $url, array $data)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$this->a['token']}", 'Accept' => 'application/json'])->post($url, $method === 'PUT' ? $data + ['_method' => 'PUT'] : $data);
    }

    private function create(array $data)
    {
        return $this->form('POST', '/api/v1/provider/activities', array_merge(['category_id' => $this->category, 'title' => 'Paradise Island Day Trip'], $data));
    }

    private function update(Activity $activity, array $data)
    {
        return $this->form('PUT', "/api/v1/provider/activities/{$activity->uuid}", $data);
    }

    private function sendJson(string $method, string $url, array $data = [])
    {
        return $this->hit($this->a['token'], $method, $url, $data);
    }

    /** An activity with $n photos on disk and in the database; the first is the cover. */
    private function withPhotos(int $n, ?Activity $activity = null): Activity
    {
        $activity ??= $this->makeActivity($this->a['provider'], 'draft');
        foreach (range(1, $n) as $i) {
            $path = "activities/seed-{$activity->id}-{$i}.png";
            Storage::disk('public')->put($path, 'x');
            $activity->images()->create(['path' => $path, 'sort_order' => $i - 1, 'is_cover' => $i === 1]);
        }

        return $activity;
    }

    private function everything(): array
    {
        return [
            'description' => 'A day on the island',
            'location' => 'Marsa Alam',
            'duration_minutes' => 480,
            'max_participants' => 12,
            'allowed_payment_methods' => ['tabby' => true, 'tamara' => false],
            'images' => [$this->png('one.png'), $this->png('two.png')],
            'options' => [['name' => 'Lunch', 'extra_price' => 50]],
            'requirements' => [['text' => 'Bring a towel', 'is_mandatory' => true]],
            'inclusions' => [['type' => 'included', 'description' => 'Equipment'], ['type' => 'excluded', 'description' => 'Meals']],
            'schedule' => [['day_of_week' => 'saturday', 'start_time' => '09:00', 'end_time' => '17:00']],
            'booking_options' => [[
                'name' => 'Standard', 'booking_model' => 'per_person', 'is_default' => true, 'min_participants' => 1, 'max_participants' => 12,
                'pricing_rules' => [['participant_type' => 'adult', 'price' => 450], ['participant_type' => 'child', 'price' => 250]],
            ]],
        ];
    }

    // --- Create: everything in one request ---------------------------------------------------------------------------

    public function test_photos_options_prices_schedule_and_everything_else_are_created_in_one_request(): void
    {
        $r = $this->create($this->everything())->assertCreated();

        $d = $r->json('data');
        $this->assertSame('Paradise Island Day Trip', $d['title']);
        $this->assertCount(2, $d['images']);
        $this->assertSame([true, false], array_column($d['images'], 'is_cover'), 'The first photo is the cover');
        $this->assertSame([450, 250], array_column($d['booking_options'][0]['pricing_rules'], 'price'));
        $this->assertSame(['Standard', 'per_person', true], [$d['booking_options'][0]['name'], $d['booking_options'][0]['booking_model'], $d['booking_options'][0]['is_default']]);
        $this->assertSame(['Bring a towel'], array_column($d['requirements'], 'text'));
        $this->assertSame(['Equipment', 'Meals'], array_column($d['inclusions'], 'description'));
        $this->assertCount(1, $d['schedule']);
        $this->assertSame(['tabby' => true, 'tamara' => false], $d['allowed_payment_methods']);
        $this->assertSame([450, 'per_person'], [$d['price'], $d['price_unit']], 'The card figures come from what was just created');
        foreach ($d['images'] as $image) {
            Storage::disk('public')->assertExists(str_replace(Storage::disk('public')->url(''), '', $image['url']));
        }
    }

    public function test_every_part_may_be_left_out(): void
    {
        $r = $this->create([])->assertCreated();

        $d = $r->json('data');
        $this->assertSame([[], [], [], [], [], []], [$d['images'], $d['booking_options'], $d['schedule'], $d['inclusions'], $d['requirements'], $d['options']]);
        $this->assertSame('published', $d['status'], 'Active by default, even with nothing else sent');
    }

    public function test_every_part_may_be_null_and_that_means_not_sent(): void
    {
        $r = $this->sendJson('POST', '/api/v1/provider/activities', ['category_id' => $this->category, 'title' => 'All nulls', 'description' => null, 'location' => null, 'images' => null,
            'features' => null, 'options' => null, 'requirements' => null, 'inclusions' => null, 'schedule' => null, 'booking_options' => null, 'allowed_payment_methods' => null])->assertCreated();

        $d = $r->json('data');
        $this->assertSame([[], [], [], [], []], [$d['images'], $d['booking_options'], $d['schedule'], $d['inclusions'], $d['options']]);
        $this->assertSame(['tabby' => false, 'tamara' => false], $d['allowed_payment_methods']);
    }

    public function test_empty_lists_are_fine_too(): void
    {
        $this->sendJson('POST', '/api/v1/provider/activities', ['category_id' => $this->category, 'title' => 'Empty lists', 'images' => [], 'options' => [], 'requirements' => [], 'inclusions' => [], 'schedule' => [],
            'booking_options' => []])->assertCreated();
    }

    public function test_a_form_upload_sends_an_empty_part_as_an_empty_string_which_is_null(): void
    {
        $r = $this->create(['images' => '', 'booking_options' => '', 'schedule' => '', 'allowed_payment_methods' => ''])->assertCreated();

        $this->assertSame([], $r->json('data.images'));
    }

    public function test_a_booking_option_may_have_no_prices_yet_or_null_prices(): void
    {
        $none = $this->create(['title' => 'No prices', 'booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person']]])->assertCreated();
        $null = $this->create(['title' => 'Null prices', 'booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person', 'pricing_rules' => null]]])->assertCreated();

        foreach ([$none, $null] as $r) {
            $this->assertSame([], $r->json('data.booking_options.0.pricing_rules'));
            $this->assertNull($r->json('data.price'));
        }
    }

    public function test_only_the_photos_the_provider_sent_are_stored_and_in_the_order_sent(): void
    {
        $this->create(['title' => 'Photos only', 'images' => [$this->png('b.png'), $this->png('a.png'), $this->png('c.png')]])->assertCreated();

        $activity = Activity::where('title', 'Photos only')->firstOrFail();
        $this->assertSame([0, 1, 2], $activity->images()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertSame(1, $activity->images()->where('is_cover', true)->count());
        $this->assertCount(3, Storage::disk('public')->files('activities'));
    }

    // --- Create: what is still checked ----------------------------------------------------------------------------------

    public function test_what_is_sent_is_still_checked(): void
    {
        $cases = [
            'too many photos' => ['images' => array_map(fn ($i) => $this->png("{$i}.png"), range(1, 11))],
            'a photo that is not an image' => ['images' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]],
            'an option with no model' => ['booking_options' => [['name' => 'Standard']]],
            'an option with no name' => ['booking_options' => [['booking_model' => 'per_person']]],
            'a price with no amount' => ['booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person', 'pricing_rules' => [['participant_type' => 'adult']]]]],
            'a negative price' => ['booking_options' => [['name' => 'Standard', 'booking_model' => 'per_person', 'pricing_rules' => [['participant_type' => 'adult', 'price' => -5]]]]],
            'an hourly option with no duration' => ['booking_options' => [['name' => 'Hourly', 'booking_model' => 'per_hour']]],
            'a day that is not a day' => ['schedule' => [['day_of_week' => 'someday', 'start_time' => '09:00', 'end_time' => '10:00']]],
            'an inclusion of no kind' => ['inclusions' => [['type' => 'maybe', 'description' => 'x']]],
        ];

        foreach ($cases as $why => $data) {
            $this->create(['title' => $why] + $data)->assertStatus(422);
        }

        $this->assertSame(1, Activity::count(), 'Nothing was created by a refused request: only the setup activity');
    }

    public function test_a_refused_create_leaves_no_photos_behind_in_the_database(): void
    {
        $this->create(['images' => [$this->png()], 'booking_options' => [['name' => 'Standard']]])->assertStatus(422);

        $this->assertSame(0, ActivityImage::count());
        $this->assertSame(0, ActivityBookingOption::where('name', 'Standard')->count() - ActivityBookingOption::where('name', 'Standard')->where('activity_id', $this->a['activity']->id)->count());
    }

    // --- Update: delete and add photos ---------------------------------------------------------------------------------

    public function test_photos_are_deleted_by_id_and_removed_from_disk(): void
    {
        $activity = $this->withPhotos(3);
        [$first, $second, $third] = $activity->images()->orderBy('sort_order')->get();

        $r = $this->update($activity, ['deleted_image_ids' => [$second->id, $third->id]])->assertOk();

        $this->assertSame([$first->id], array_column($r->json('data.images'), 'id'));
        $this->assertSame(1, $activity->images()->count());
        Storage::disk('public')->assertExists($first->path);
        Storage::disk('public')->assertMissing($second->path);
        Storage::disk('public')->assertMissing($third->path);
    }

    public function test_deleting_the_cover_hands_the_cover_to_the_next_photo(): void
    {
        $activity = $this->withPhotos(3);
        [$cover, $next, $last] = $activity->images()->orderBy('sort_order')->get();

        $this->update($activity, ['deleted_image_ids' => [$cover->id]])->assertOk();

        $this->assertTrue($next->refresh()->is_cover);
        $this->assertFalse($last->refresh()->is_cover);
        $this->assertSame(1, $activity->images()->where('is_cover', true)->count());
    }

    public function test_new_photos_are_added_under_either_name_and_keep_the_existing_ones(): void
    {
        $activity = $this->withPhotos(1);

        $r = $this->update($activity, ['new_images' => [$this->png('a.png'), $this->png('b.png')]])->assertOk();
        $this->assertCount(3, $r->json('data.images'));

        $r = $this->update($activity, ['images' => [$this->png('c.png')]])->assertOk();
        $this->assertCount(4, $r->json('data.images'), 'The older name `images` still adds');

        $r = $this->update($activity, ['images' => [$this->png('d.png')], 'new_images' => [$this->png('e.png')]])->assertOk();
        $this->assertCount(6, $r->json('data.images'), 'Both names in one request add both');
        $this->assertSame(1, $activity->images()->where('is_cover', true)->count());
    }

    public function test_deleting_and_adding_in_one_request_replaces_the_photos(): void
    {
        $activity = $this->withPhotos(2);
        $old = $activity->images()->pluck('id')->all();

        $r = $this->update($activity, ['deleted_image_ids' => $old, 'new_images' => [$this->png('x.png'), $this->png('y.png')]])->assertOk();

        $ids = array_column($r->json('data.images'), 'id');
        $this->assertCount(2, $ids);
        $this->assertSame([], array_intersect($ids, $old));
        $this->assertSame([true, false], array_column($r->json('data.images'), 'is_cover'), 'With every old photo gone, the first new one is the cover');
    }

    public function test_an_activity_can_end_up_with_no_photos(): void
    {
        $activity = $this->withPhotos(2);

        $r = $this->update($activity, ['deleted_image_ids' => $activity->images()->pluck('id')->all()])->assertOk();

        $this->assertSame([], $r->json('data.images'));
    }

    public function test_a_photo_that_is_not_this_activitys_or_does_not_exist_is_refused_and_nothing_changes(): void
    {
        $mine = $this->withPhotos(2);
        $other = $this->withPhotos(1, $this->a['activity']);
        $theirs = $other->images()->first();
        $someoneElsesActivity = $this->approvedProvider();
        $strangers = $this->withPhotos(1, $someoneElsesActivity['activity'])->images()->first();
        $keep = $mine->images()->first();

        foreach ([[$theirs->id], [$strangers->id], [99999999], [$keep->id, 99999999]] as $ids) {
            $this->update($mine, ['deleted_image_ids' => $ids])->assertStatus(422)->assertJsonValidationErrors('deleted_image_ids.'.(count($ids) - 1));
        }

        $this->assertSame(2, $mine->images()->count(), 'Not even the good id in a bad list was deleted');
        $this->assertSame(1, $this->a['activity']->images()->count());
        $this->assertNotNull(ActivityImage::find($strangers->id), "Another provider's photo is untouched");
        Storage::disk('public')->assertExists($keep->path);
    }

    public function test_the_list_of_ids_is_checked(): void
    {
        $activity = $this->withPhotos(2);
        $id = $activity->images()->first()->id;

        foreach ([['deleted_image_ids' => 'all'], ['deleted_image_ids' => ['x']], ['deleted_image_ids' => [$id, $id]], ['deleted_image_ids' => [1.5]]] as $bad) {
            $this->update($activity, $bad)->assertStatus(422);
        }

        $this->assertSame(2, $activity->images()->count());
    }

    public function test_the_activity_can_have_at_most_ten_photos_in_all(): void
    {
        $activity = $this->withPhotos(8);
        $ids = $activity->images()->pluck('id')->all();

        $this->update($activity, ['new_images' => [$this->png('1.png'), $this->png('2.png'), $this->png('3.png')]])->assertStatus(422)->assertJsonValidationErrors('new_images');
        $this->assertSame(8, $activity->images()->count(), 'Refused as a whole: none of the three was added');

        $this->update($activity, ['new_images' => [$this->png('1.png'), $this->png('2.png')]])->assertOk();
        $this->assertSame(10, $activity->images()->count(), 'Exactly ten is allowed');

        $this->update($activity, ['new_images' => [$this->png('x.png')]])->assertStatus(422);
        $this->update($activity, ['deleted_image_ids' => array_slice($ids, 0, 2), 'new_images' => [$this->png('x.png'), $this->png('y.png')]])->assertOk();
        $this->assertSame(10, $activity->images()->count(), 'Deleting makes room in the same request');
    }

    public function test_new_photos_are_checked_like_any_upload(): void
    {
        $activity = $this->withPhotos(1);

        foreach ([UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('big.png', 5000, 'image/png')] as $file) {
            $this->update($activity, ['new_images' => [$file]])->assertStatus(422)->assertJsonValidationErrors('new_images.0');
        }

        $this->assertSame(1, $activity->images()->count());
    }

    public function test_null_and_empty_lists_change_no_photos(): void
    {
        $activity = $this->withPhotos(2);

        $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['deleted_image_ids' => null, 'new_images' => null, 'images' => null])->assertOk();
        $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['deleted_image_ids' => [], 'new_images' => []])->assertOk();
        $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['title' => 'Only the title'])->assertOk();

        $this->assertSame(2, $activity->images()->count());
    }

    // --- Update: the other parts ----------------------------------------------------------------------------------------

    private function full(): Activity
    {
        $id = $this->create($this->everything() + [])->assertCreated()->json('data.uuid');

        return Activity::where('uuid', $id)->firstOrFail();
    }

    public function test_a_part_that_is_null_or_missing_is_left_alone_on_update(): void
    {
        $activity = $this->full();

        $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['title' => 'Renamed', 'options' => null, 'requirements' => null, 'inclusions' => null, 'schedule' => null, 'booking_options' => null, 'features' => null])->assertOk();
        $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['description' => 'New words'])->assertOk();

        $activity->refresh();
        $this->assertSame('Renamed', $activity->title);
        $this->assertSame([1, 1, 2, 1, 1], [$activity->options()->count(), $activity->requirements()->count(), $activity->inclusions()->count(), $activity->schedulePeriods()->count(), $activity->bookingOptions()->count()],
            'A null schedule used to delete the schedule; now nothing is touched');
        $this->assertSame(2, $activity->bookingOptions()->first()->pricingRules()->count());
        $this->assertSame(2, $activity->images()->count());
    }

    public function test_an_empty_list_clears_that_part_on_update(): void
    {
        $activity = $this->full();

        $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['requirements' => [], 'inclusions' => [], 'schedule' => []])->assertOk();

        $activity->refresh();
        $this->assertSame([0, 0, 0, 1, 1], [$activity->requirements()->count(), $activity->inclusions()->count(), $activity->schedulePeriods()->count(), $activity->options()->count(), $activity->bookingOptions()->count()]);
    }

    public function test_booking_options_and_prices_can_be_replaced_in_the_same_request_as_the_photos(): void
    {
        $activity = $this->full();
        $photo = $activity->images()->first();

        $r = $this->update($activity, ['deleted_image_ids' => [$photo->id], 'new_images' => [$this->png('n.png')], 'booking_options' => [[
            'name' => 'Private', 'booking_model' => 'per_group', 'is_default' => true, 'pricing_rules' => [['participant_type' => 'adult', 'price' => 3000]]]]])->assertOk();

        $d = $r->json('data');
        $this->assertSame(['Private'], array_column($d['booking_options'], 'name'));
        $this->assertSame([3000, 'per_group'], [$d['price'], $d['price_unit']]);
        $this->assertCount(2, $d['images']);
        $this->assertNotContains($photo->id, array_column($d['images'], 'id'));
    }

    public function test_the_payment_flags_may_be_null_on_update(): void
    {
        $activity = $this->full();

        $r = $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['allowed_payment_methods' => null])->assertOk();
        $this->assertSame(['tabby' => true, 'tamara' => false], $r->json('data.allowed_payment_methods'), 'null leaves them');

        $r = $this->sendJson('PUT', "/api/v1/provider/activities/{$activity->uuid}", ['allowed_payment_methods' => ['tabby' => null, 'tamara' => true]])->assertOk();
        $this->assertSame(['tabby' => true, 'tamara' => true], $r->json('data.allowed_payment_methods'), 'A null flag is not sent');
    }

    // --- One request, all or nothing -----------------------------------------------------------------------------------

    private function failAfterThePhotos(): void
    {
        // The schedule is applied after the photos, so a failure there comes when photos have already been deleted and stored.
        $this->mock(ScheduleService::class, fn ($m) => $m->shouldReceive('create')->andThrow(new \RuntimeException('Something broke half-way')));
    }

    public function test_an_update_that_fails_part_way_changes_nothing_and_loses_no_photo_file(): void
    {
        $activity = $this->withPhotos(2);
        $before = $activity->images()->orderBy('id')->get(['id', 'path'])->map->only(['id', 'path'])->all();
        $this->failAfterThePhotos();

        $this->update($activity, ['title' => 'Should not stick', 'deleted_image_ids' => [$before[0]['id']], 'new_images' => [$this->png('new.png')],
            'schedule' => [['day_of_week' => 'saturday', 'start_time' => '09:00', 'end_time' => '10:00']]])->assertStatus(500);

        $this->assertSame($before, $activity->images()->orderBy('id')->get(['id', 'path'])->map->only(['id', 'path'])->all(), 'The photo rows are as they were');
        $this->assertNotSame('Should not stick', $activity->refresh()->title);
        foreach ($before as $image) {
            Storage::disk('public')->assertExists($image['path']);   // the deleted photo's file is still there: its row came back
        }
        $this->assertCount(2, Storage::disk('public')->files('activities'), 'And the photo that was stored on the way is not left behind');
    }

    public function test_a_create_that_fails_part_way_leaves_no_activity_and_no_files(): void
    {
        $this->failAfterThePhotos();

        $this->create(['title' => 'Should not exist', 'images' => [$this->png('a.png'), $this->png('b.png')], 'schedule' => [['day_of_week' => 'saturday', 'start_time' => '09:00', 'end_time' => '10:00']]])->assertStatus(500);

        $this->assertNull(Activity::where('title', 'Should not exist')->first());
        $this->assertSame([], Storage::disk('public')->files('activities'));
        $this->assertSame(0, ActivityImage::count());
    }

    public function test_a_photo_file_is_removed_only_when_the_update_has_succeeded(): void
    {
        $activity = $this->withPhotos(2);
        $gone = $activity->images()->first();

        $this->update($activity, ['deleted_image_ids' => [$gone->id]])->assertOk();

        Storage::disk('public')->assertMissing($gone->path);
        $this->assertNull(ActivityImage::find($gone->id));
    }

    // --- The same update as a POST ---------------------------------------------------------------------------------------

    public function test_a_plain_post_to_the_activity_is_the_same_update_with_no_method_field(): void
    {
        $activity = $this->withPhotos(2);
        $gone = $activity->images()->first();
        $this->app['auth']->forgetGuards();

        $r = $this->withHeaders(['Authorization' => "Bearer {$this->a['token']}", 'Accept' => 'application/json'])
            ->post("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'By a plain POST', 'deleted_image_ids' => [$gone->id], 'new_images' => [$this->png('n.png')]])->assertOk();

        $this->assertSame('By a plain POST', $r->json('data.title'));
        $this->assertCount(2, $r->json('data.images'));
        $this->assertNotContains($gone->id, array_column($r->json('data.images'), 'id'));
    }

    public function test_the_post_is_for_the_owner_only_and_checks_the_same_things(): void
    {
        $activity = $this->withPhotos(1);
        $other = $this->approvedProvider();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$other['token']}", 'Accept' => 'application/json'])->post("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'Mine now'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$this->a['token']}", 'Accept' => 'application/json'])->post("/api/v1/provider/activities/{$activity->uuid}", ['deleted_image_ids' => [99999999]])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'application/json'])->post("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'Anonymous'])->assertUnauthorized();

        $this->assertNotSame('Mine now', $activity->refresh()->title);
        $this->assertSame(1, $activity->images()->count());
    }

    // --- Everything at once --------------------------------------------------------------------------------------------------

    public function test_one_update_can_change_every_part_of_an_activity_at_once(): void
    {
        $activity = $this->full();
        $oldPhoto = $activity->images()->first();
        $keptPhoto = $activity->images()->where('id', '!=', $oldPhoto->id)->first();

        $r = $this->update($activity, [
            'title' => 'Sunrise Dive', 'description' => 'New description', 'location' => 'Hurghada', 'meeting_point' => 'Marina Gate 4', 'duration_minutes' => 120, 'min_age' => 10, 'max_participants' => 8,
            'allowed_payment_methods' => ['tamara' => true],
            'options' => [['name' => 'Snacks', 'extra_price' => 25], ['name' => 'Photos', 'extra_price' => 40]],
            'requirements' => [['text' => 'Swimming ability', 'is_mandatory' => true]],
            'inclusions' => [['type' => 'included', 'description' => 'Boat'], ['type' => 'excluded', 'description' => 'Transport'], ['type' => 'excluded', 'description' => 'Tips']],
            'schedule' => [['day_of_week' => 'sunday', 'start_time' => '06:00', 'end_time' => '09:00'], ['day_of_week' => 'monday', 'start_time' => '06:00', 'end_time' => '09:00']],
            'booking_options' => [['name' => 'Group', 'booking_model' => 'per_group', 'is_default' => true, 'pricing_rules' => [['participant_type' => 'adult', 'price' => 900]]]],
            'deleted_image_ids' => [$oldPhoto->id],
            'new_images' => [$this->png('x.png'), $this->png('y.png')],
        ])->assertOk();

        $d = $r->json('data');
        $this->assertSame(['Sunrise Dive', 'New description', 'Hurghada', 'Marina Gate 4', 120, 10, 8], [$d['title'], $d['description'], $d['location'], $d['meeting_point'], $d['duration_minutes'], $d['min_age'], $d['max_participants']]);
        $this->assertSame(['tabby' => true, 'tamara' => true], $d['allowed_payment_methods'], 'The flag that was not sent stays');
        $this->assertSame(['Snacks', 'Photos'], array_column($d['options'], 'name'));
        $this->assertSame(['Swimming ability'], array_column($d['requirements'], 'text'));
        $this->assertSame(['Boat', 'Transport', 'Tips'], array_column($d['inclusions'], 'description'));
        $this->assertCount(2, $d['schedule']);
        $this->assertSame(['Group'], array_column($d['booking_options'], 'name'));
        $this->assertSame([900, 'per_group'], [$d['price'], $d['price_unit']]);
        $ids = array_column($d['images'], 'id');
        $this->assertCount(3, $ids, 'Two photos kept or added, one more added, one deleted');
        $this->assertNotContains($oldPhoto->id, $ids);
        $this->assertContains($keptPhoto->id, $ids);
        $this->assertSame(1, $activity->images()->where('is_cover', true)->count());
    }

    // --- Who ---------------------------------------------------------------------------------------------------------------

    public function test_another_providers_activity_cannot_have_its_photos_changed(): void
    {
        $other = $this->approvedProvider();
        $theirs = $this->withPhotos(2, $other['activity']);
        $id = $theirs->images()->first()->id;

        $this->update($other['activity'], ['deleted_image_ids' => [$id], 'new_images' => [$this->png()]])->assertForbidden();

        $this->assertSame(2, $theirs->images()->count());
        Storage::disk('public')->assertExists($theirs->images()->first()->path);
    }

    public function test_a_customer_and_nobody_are_refused(): void
    {
        $activity = $this->withPhotos(1);
        [$customer] = $this->makeCustomer();

        $this->hit($this->tokenFor($customer, 'customer'), 'PUT', "/api/v1/provider/activities/{$activity->uuid}", ['deleted_image_ids' => [1]])->assertForbidden();
        $this->hit(null, 'PUT', "/api/v1/provider/activities/{$activity->uuid}", ['deleted_image_ids' => [1]])->assertUnauthorized();
        $this->assertSame(1, $activity->images()->count());
    }
}
