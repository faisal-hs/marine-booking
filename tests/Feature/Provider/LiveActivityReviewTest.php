<?php

namespace Tests\Feature\Provider;

use App\Models\Activity;
use App\Models\ActivityImage;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Notifications\Activities\ActivitySentBackToReviewNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\Concerns\RelativeDates;
use Tests\TestCase;

/**
 * A provider could edit a PUBLISHED activity freely after an admin approved it: swap
 * the photos, rewrite the description, change the category, all without a second
 * look. Content customers rely on to decide now sends a live activity back to review,
 * and takes it off the public site until it is approved again.
 *
 * What counts as content is deliberately narrow (title, description, category,
 * location, images, features, requirements, inclusions): providers change schedules,
 * blackout dates, prices and extras day to day, and a customer's price is frozen on
 * their reservation when they book.
 */
class LiveActivityReviewTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;
    use RelativeDates;

    /** Every provider write route that changes customer-visible content. */
    private const CONTENT_ROUTES = [
        'provider.activities.update',
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
        'provider.activities.store', 'provider.activities.destroy', 'provider.activities.submit-for-review',
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

    private function sentBackNotifications(int $userId): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $userId)
            ->where('type', ActivitySentBackToReviewNotification::class)
            ->count();
    }

    // --- Content edits send a live activity back --------------------------------

    /** @return array<string, array{0: string, 1: string}> [route name, edit] */
    public static function contentEdits(): array
    {
        return [
            'a new title' => ['provider.activities.update', 'title'],
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
    public function test_a_content_edit_sends_a_live_activity_back_to_review(string $route, string $edit): void
    {
        $live = $this->liveActivity();
        $activity = $live['activity'];

        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk();

        $response = $this->applyContentEdit($edit, $live);

        $response->assertSuccessful();
        $this->assertStringContainsString('sent back for review', (string) $response->json('message'));

        $activity->refresh();
        $this->assertSame('pending_review', $activity->status);
        $this->assertNotNull($activity->published_at, 'The first publication date is kept; it is how a re-review is recognised.');

        // Off the public site until it is approved again.
        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertNotFound();

        // The provider is told, once.
        $this->assertSame(1, $this->sentBackNotifications($live['user']->id));
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
        $this->assertStringNotContainsString('sent back', (string) $response->json('message'));
        $this->assertSame('published', $activity->fresh()->status);
        $this->assertSame(0, $this->sentBackNotifications($live['user']->id));
        $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk();
    }

    // --- The case that must never go wrong -------------------------------------

    public function test_resaving_an_unchanged_form_does_not_unpublish_the_activity(): void
    {
        // The combined update REPLACES features, requirements and inclusions on every
        // save. An app that re-sends the whole form with nothing changed must leave the
        // listing live: the guard compares what customers see, not what was written.
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
        $this->assertStringNotContainsString('sent back', (string) $response->json('message'));
        $this->assertSame('published', $activity->fresh()->status);
        $this->assertSame(0, $this->sentBackNotifications($live['user']->id));
    }

    // --- Only a LIVE activity is guarded ---------------------------------------

    /** @return array<string, array{0: string}> */
    public static function nonLiveStatuses(): array
    {
        return [
            'a draft' => ['draft'],
            'an activity already in review' => ['pending_review'],
            'a rejected activity' => ['rejected'],
            'a deactivated activity' => ['inactive'],
        ];
    }

    #[DataProvider('nonLiveStatuses')]
    public function test_an_activity_that_is_not_live_is_edited_freely(string $status): void
    {
        [$user, $provider, $token] = $this->makeProvider('verified');
        $activity = $this->makeActivity($provider, $status);

        $this->asProvider($token)
            ->putJson("/api/v1/provider/activities/{$activity->uuid}", ['title' => "Renamed while {$status}"])
            ->assertOk();

        $this->assertSame($status, $activity->fresh()->status, 'A '.$status.' activity must keep its status.');
        $this->assertSame(0, $this->sentBackNotifications($user->id));
    }

    // --- Going back and forth ----------------------------------------------------

    public function test_a_second_edit_while_it_is_already_in_review_does_not_notify_again(): void
    {
        $live = $this->liveActivity();
        $this->applyContentEdit('title', $live)->assertOk();
        $this->applyContentEdit('description', $live)->assertOk();

        $this->assertSame('pending_review', $live['activity']->fresh()->status);
        $this->assertSame(1, $this->sentBackNotifications($live['user']->id));
    }

    public function test_after_re_approval_a_later_edit_sends_it_back_again(): void
    {
        $live = $this->liveActivity();
        $admin = $this->makeAdmin();

        $this->applyContentEdit('title', $live)->assertOk();
        $this->actingAs($admin, 'web')->post(route('admin.activities.approve', $live['activity']))->assertSessionHasNoErrors();
        $this->assertSame('published', $live['activity']->fresh()->status);

        $this->applyContentEdit('description', $live)->assertOk();

        $this->assertSame('pending_review', $live['activity']->fresh()->status);
        $this->assertSame(2, $this->sentBackNotifications($live['user']->id), 'Each time is a new occurrence and is notified.');
    }

    public function test_the_admin_list_marks_a_re_review_but_not_a_new_submission(): void
    {
        $live = $this->liveActivity();
        $this->applyContentEdit('title', $live)->assertOk();

        [, $provider] = $this->makeProvider('verified');
        $this->makeActivity($provider, 'pending_review', ['title' => 'Brand New Activity']);

        $page = $this->actingAs($this->makeAdmin(), 'web')->get(route('admin.activities.index'));

        $page->assertOk()->assertSee(__('admin.activities.edited_after_publication'));
        $this->assertSame(1, substr_count($page->getContent(), __('admin.activities.edited_after_publication')));
    }

    // --- Nothing can be added without a decision --------------------------------

    public function test_every_provider_write_route_under_an_activity_is_classified(): void
    {
        // If you add a provider endpoint that changes an activity, this fails until you
        // decide which kind it is. A content endpoint must also be wrapped with
        // editWithReviewGuard() in its controller (see GuardsLiveActivityContent).
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
