<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\ActivityImage;
use App\Models\Category;
use App\Models\FeatureDefinition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

/**
 * A provider edits their own activity, live or not, and the edit applies at once. Nobody approves an activity (the admin approves the PROVIDER
 * account), so there is nothing to send it back to: a live activity stays live, a ready one stays ready, a draft stays a draft, and customers see
 * the change straight away. What can stop an activity is an admin's suspension, a separate act that an edit never lifts.
 */
class ProviderLiveActivityEditTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;
    use RelativeDates;

    /** Every provider write route that changes customer-visible content. */
    private const CONTENT_ROUTES = [
        'provider.activities.update',
        'provider.activities.update.post',   // the same update, as a POST (for photos)
        'provider.activities.images.store',
        'provider.activities.images.destroy',
        'provider.activities.images.set-cover',
        'provider.activities.images.reorder',
        'provider.activities.features.sync',
        'provider.activities.requirements.sync',
        'provider.activities.inclusions.sync',
    ];

    /** Written day to day, never needing review. */
    private const OPERATIONAL_ROUTES = [
        'provider.activities.schedule.store', 'provider.activities.schedule.update', 'provider.activities.schedule.destroy',
        'provider.activities.overrides.store', 'provider.activities.overrides.update', 'provider.activities.overrides.destroy',
        'provider.activities.options.sync',
        'provider.activities.booking-options.store', 'provider.activities.booking-options.update', 'provider.activities.booking-options.destroy',
        'provider.activities.booking-options.pricing-rules.store', 'provider.activities.booking-options.pricing-rules.update', 'provider.activities.booking-options.pricing-rules.destroy',
    ];

    /** Moving the activity itself through its life; not an edit of what it says. */
    private const LIFECYCLE_ROUTES = [
        'provider.activities.store', 'provider.activities.destroy', 'provider.activities.publish', 'provider.activities.submit-for-review',
        // The provider app's publish / pause / resume: it never changes what the activity says, and it can never switch off an admin's suspension.
        'provider.activities.status',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    /**
     * A live activity with content in every place the guard watches. The content is
     * added while it is still a draft (the guard only watches live activities), and
     * the activity is published afterwards.
     *
     * @return array{activity: Activity, user: \App\Models\User, provider: \App\Models\Provider, token: string, images: array, other: Category}
     */
    private function liveActivity(): array
    {
        [$user, $provider, $token] = $this->makeProvider('verified');
        $category = $this->makeCategory();
        $other = $this->makeCategory('Snorkeling');

        FeatureDefinition::create(['category_id' => $category->id, 'key' => 'dive_depth', 'label' => 'Dive Depth', 'type' => 'number', 'is_required' => false, 'sort_order' => 1]);

        $activity = $this->makeActivity($provider, 'draft', ['category' => $category, 'lat' => 27.2, 'lng' => 33.8]);

        $images = [
            ActivityImage::create(['activity_id' => $activity->id, 'path' => 'activities/a.jpg', 'sort_order' => 1, 'is_cover' => true]),
            ActivityImage::create(['activity_id' => $activity->id, 'path' => 'activities/b.jpg', 'sort_order' => 2, 'is_cover' => false]),
        ];

        $base = "/api/v1/provider/activities/{$activity->uuid}";
        $this->asProvider($token)->putJson("{$base}/features", ['features' => ['dive_depth' => 18]])->assertOk();
        $this->asProvider($token)->putJson("{$base}/requirements", ['requirements' => [['text' => 'Valid certification', 'is_mandatory' => true]]])->assertOk();
        $this->asProvider($token)->putJson("{$base}/inclusions", ['inclusions' => [['type' => 'included', 'description' => 'Equipment']]])->assertOk();

        $activity->update(['status' => 'published', 'published_at' => now()]);

        return compact('activity', 'user', 'provider', 'token', 'images', 'other');
    }

    // --- Content edits apply at once and leave a live activity live -----------------

    /** @return array<string, array{0: string, 1: string}> [route name, edit] */
    public static function contentEdits(): array
    {
        return [
            'a new title' => ['provider.activities.update', 'title'],
            'a new title as a plain POST' => ['provider.activities.update.post', 'title_post'],
            'a new description' => ['provider.activities.update', 'description'],
            'a new location' => ['provider.activities.update', 'location'],
            'a new meeting point' => ['provider.activities.update', 'meeting_point'],
            'new coordinates' => ['provider.activities.update', 'coordinates'],
            'a new category' => ['provider.activities.update', 'category'],
            'an extra photo' => ['provider.activities.images.store', 'image_upload'],
            'a deleted photo' => ['provider.activities.images.destroy', 'image_delete'],
            'a different cover photo' => ['provider.activities.images.set-cover', 'image_cover'],
            'reordered photos' => ['provider.activities.images.reorder', 'image_reorder'],
            'a changed feature value' => ['provider.activities.features.sync', 'features'],
            'changed requirements' => ['provider.activities.requirements.sync', 'requirements'],
            'changed inclusions' => ['provider.activities.inclusions.sync', 'inclusions'],
        ];
    }

    private function applyContentEdit(string $edit, array $live)
    {
        ['activity' => $activity, 'token' => $token, 'images' => $images, 'other' => $other] = $live;
        $base = "/api/v1/provider/activities/{$activity->uuid}";
        $as = fn () => $this->asProvider($token);

        return match ($edit) {
            'title' => $as()->putJson($base, ['title' => 'Renamed Reef Dive']),
            'title_post' => $as()->post($base, ['title' => 'Renamed by a POST'], ['Accept' => 'application/json']),
            'description' => $as()->putJson($base, ['description' => 'A completely different description.']),
            'location' => $as()->putJson($base, ['location' => 'Dahab']),
            'meeting_point' => $as()->putJson($base, ['meeting_point' => 'Another dock']),
            'coordinates' => $as()->putJson($base, ['lat' => 28.5, 'lng' => 34.5]),
            'category' => $as()->putJson($base, ['category_id' => $other->id]),
            'image_upload' => $as()->post("{$base}/images", ['images' => [UploadedFile::fake()->image('new.jpg')]], ['Accept' => 'application/json']),
            'image_delete' => $as()->deleteJson("{$base}/images/{$images[1]->uuid}"),
            'image_cover' => $as()->putJson("{$base}/images/{$images[1]->uuid}/cover"),
            'image_reorder' => $as()->putJson("{$base}/images/reorder", ['order' => [$images[1]->uuid, $images[0]->uuid]]),
            'features' => $as()->putJson("{$base}/features", ['features' => ['dive_depth' => 25]]),
            'requirements' => $as()->putJson("{$base}/requirements", ['requirements' => [['text' => 'Bring a towel', 'is_mandatory' => false]]]),
            'inclusions' => $as()->putJson("{$base}/inclusions", ['inclusions' => [['type' => 'included', 'description' => 'Lunch']]]),
        };
    }

    #[DataProvider('contentEdits')]
    public function test_a_content_edit_applies_at_once_and_leaves_a_live_activity_live(string $route, string $edit): void
    {
        $live = $this->liveActivity();
        $activity = $live['activity'];

        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk();

        $response = $this->applyContentEdit($edit, $live);

        $response->assertSuccessful();
        $this->assertStringNotContainsString('review', (string) $response->json('message'), 'Nobody reviews an activity, so the response never talks of review.');

        $activity->refresh();
        $this->assertSame('published', $activity->status);
        $this->assertNotNull($activity->published_at);

        // Still on the public site, and with the change.
        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk();
    }

    public function test_every_content_route_has_an_edit_case_above(): void
    {
        $covered = array_values(array_unique(array_column(self::contentEdits(), 0)));

        $this->assertEqualsCanonicalizing(self::CONTENT_ROUTES, $covered);
    }

    // --- Operational edits never need a review -----------------------------------

    /** @return array<string, array{0: string}> */
    public static function operationalEdits(): array
    {
        return [
            'participant limits, duration and minimum age' => ['limits'],
            'a new weekly schedule slot' => ['schedule'],
            'a blackout date' => ['blackout'],
            'paid extras' => ['extras'],
            'a booking option description' => ['booking_option'],
            'a price' => ['price'],
        ];
    }

    #[DataProvider('operationalEdits')]
    public function test_an_operational_edit_leaves_a_live_activity_live(string $edit): void
    {
        $live = $this->liveActivity();
        ['activity' => $activity, 'token' => $token] = $live;
        $base = "/api/v1/provider/activities/{$activity->uuid}";
        $as = fn () => $this->asProvider($token);

        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-'.uniqid(), 'booking_model' => 'per_person']);
        $rule = $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 150]);

        $response = match ($edit) {
            'limits' => $as()->putJson($base, ['min_participants' => 2, 'max_participants' => 12, 'duration_minutes' => 90, 'min_age' => 10]),
            'schedule' => $as()->postJson("{$base}/schedule", ['day_of_week' => 'sunday', 'start_time' => '09:00', 'end_time' => '12:00']),
            'blackout' => $as()->postJson("{$base}/overrides", ['date_from' => $this->daysFromNow(10)->toDateString(), 'date_to' => $this->daysFromNow(10)->toDateString(), 'is_unavailable' => true]),
            'extras' => $as()->putJson("{$base}/options", ['options' => [['name' => 'Underwater Photos', 'extra_price' => 25, 'is_required' => false]]]),
            'booking_option' => $as()->putJson("{$base}/booking-options/{$option->uuid}", ['description' => 'Updated description']),
            'price' => $as()->putJson("{$base}/booking-options/{$option->uuid}/pricing-rules/{$rule->uuid}", ['price' => 175]),
        };

        $response->assertSuccessful();
        $this->assertStringNotContainsString('review', (string) $response->json('message'));
        $this->assertSame('published', $activity->fresh()->status);
        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk();
    }

    // --- The case that must never go wrong -------------------------------------

    public function test_resaving_the_whole_form_leaves_the_activity_live(): void
    {
        // The combined update REPLACES features, requirements and inclusions on every save. An app that re-sends the whole form must leave the listing live.
        $live = $this->liveActivity();
        ['activity' => $activity, 'token' => $token] = $live;

        $response = $this->asProvider($token)->putJson("/api/v1/provider/activities/{$activity->uuid}", [
            'title' => 'Reef Dive',
            'description' => 'A guided dive on the reef.',
            'location' => 'Hurghada',
            'meeting_point' => 'Marina dock 1',
            'lat' => 27.2,
            'lng' => 33.8,
            'features' => ['dive_depth' => 18],
            'requirements' => [['text' => 'Valid certification', 'is_mandatory' => true]],
            'inclusions' => [['type' => 'included', 'description' => 'Equipment']],
        ]);

        $response->assertOk();
        $this->assertSame('published', $activity->fresh()->status);
    }

    // --- Whatever the activity is, an edit leaves it as it is ------------------------

    /** @return array<string, array{0: string}> */
    public static function nonLiveStatuses(): array
    {
        return [
            'a draft' => ['draft'],
            'a ready activity, waiting for its provider to activate it' => ['approved'],
            'an activity its provider paused' => ['inactive'],
        ];
    }

    #[DataProvider('nonLiveStatuses')]
    public function test_an_activity_that_is_not_live_is_edited_and_keeps_its_status(string $status): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, $status);

        $this->asProvider($token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", ['title' => "Renamed while {$status}"])
            ->assertOk();

        $this->assertSame($status, $activity->fresh()->status, 'A '.$status.' activity must keep its status.');
        $this->assertSame("Renamed while {$status}", $activity->fresh()->title);
    }

    // --- An edit never undoes an admin's suspension --------------------------------

    public function test_editing_a_suspended_activity_does_not_lift_the_suspension_or_put_it_live(): void
    {
        $live = $this->liveActivity();
        ['activity' => $activity] = $live;
        $activity->update(['status' => 'inactive', 'suspended_at' => now(), 'suspension_reason' => 'A complaint', 'suspended_from_status' => 'published']);

        // Every way of trying to say "live again" in the body of an edit is ignored.
        $this->asProvider($live['token'])
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", ['title' => 'A better title', 'status' => 'published', 'suspended_at' => null, 'suspension_reason' => null, 'suspended_from_status' => null])
            ->assertOk();

        $activity->refresh();
        $this->assertSame('A better title', $activity->title, 'The edit itself applied');
        $this->assertSame('inactive', $activity->status);
        $this->assertTrue($activity->isSuspended());
        $this->assertSame('A complaint', $activity->suspension_reason);
        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertNotFound();
    }

    public function test_a_provider_cannot_change_the_status_of_an_activity_through_an_edit(): void
    {
        [, $provider, $token] = $this->makeProvider('verified');
        $draft = $this->makeActivity($provider, 'draft');

        $this->asProvider($token)->putJson("/api/v1/provider/activities/{$draft->uuid}", ['title' => 'Going live', 'status' => 'published'])->assertOk();

        $this->assertSame('draft', $draft->fresh()->status, 'Going live is the publish request, never a field of the edit.');
    }

    // --- Nothing can be added without a decision --------------------------------

    public function test_every_provider_write_route_under_an_activity_is_classified(): void
    {
        // If you add a provider endpoint that changes an activity, this fails until you decide which kind it is: an edit (content or operational,
        // which applies at once and must leave the activity as it is) or a lifecycle step (which moves it through its life).
        $classified = array_merge(self::CONTENT_ROUTES, self::OPERATIONAL_ROUTES, self::LIFECYCLE_ROUTES);
        $unclassified = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $isProviderActivityRoute = str_starts_with($route->uri(), 'api/v1/provider/activities');
            $writes = array_diff($route->methods(), ['GET', 'HEAD']) !== [];

            if ($isProviderActivityRoute && $writes && ! in_array($route->getName(), $classified, true)) {
                $unclassified[] = $route->getName() ?? $route->uri();
            }
        }

        $this->assertSame([], $unclassified, 'Classify these provider routes as content, operational or lifecycle: '.implode(', ', $unclassified));
    }
}
