<?php

namespace Tests\Feature\Notifications;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\Activities\ActivitySuspendedNotification;
use App\Notifications\Activities\ActivitySuspensionLiftedNotification;
use App\Services\ActivityPublishingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Who is told what when an admin suspends an activity and lifts the suspension: only the provider, with the reason, on the right channels. And who is
 * NOT told: a provider is never notified of their own publishing or pausing, they have just done it.
 */
class ActivitySuspensionNotificationsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeAdmin();
    }

    /** @return array{0: Activity, 1: User} */
    private function liveActivity(string $status = 'published'): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-'.uniqid(), 'verification_status' => 'verified']);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-'.uniqid(), 'status' => 'active']);
        $activity = Activity::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'title' => 'Test Activity', 'slug' => 'test-activity-'.uniqid(), 'status' => $status]);

        return [$activity, $providerUser];
    }

    public function test_suspending_an_activity_notifies_only_the_provider(): void
    {
        NotificationFacade::fake();
        [$activity, $providerUser] = $this->liveActivity();

        app(ActivityPublishingService::class)->suspend($activity, 'A safety problem', $this->admin);

        NotificationFacade::assertSentTo($providerUser, ActivitySuspendedNotification::class);
        NotificationFacade::assertNothingSentTo($this->admin);
    }

    public function test_lifting_a_suspension_notifies_only_the_provider(): void
    {
        [$activity, $providerUser] = $this->liveActivity();
        $service = app(ActivityPublishingService::class);
        $service->suspend($activity, 'A safety problem', $this->admin);
        NotificationFacade::fake();

        $service->lift($activity->refresh());

        NotificationFacade::assertSentTo($providerUser, ActivitySuspensionLiftedNotification::class);
        NotificationFacade::assertNothingSentTo($this->admin);
    }

    public function test_the_channels(): void
    {
        [$activity, $providerUser] = $this->liveActivity();

        $this->assertEquals(['database', 'fcm', 'mail'], (new ActivitySuspendedNotification($activity))->via($providerUser));
        $this->assertEquals(['database', 'fcm', 'mail'], (new ActivitySuspensionLiftedNotification($activity))->via($providerUser));
    }

    public function test_the_suspension_message_gives_the_reason_and_says_only_an_admin_can_lift_it(): void
    {
        [$activity, $providerUser] = $this->liveActivity();
        $suspended = app(ActivityPublishingService::class)->suspend($activity, 'Photos that are not theirs', $this->admin);
        $notification = new ActivitySuspendedNotification($suspended);

        $data = $notification->toArray($providerUser);
        $mail = implode(' ', array_map(fn ($l) => (string) $l, $notification->toMail($providerUser)->introLines));

        $this->assertSame('activity.suspended', $data['type']);
        $this->assertStringContainsString('Photos that are not theirs', $data['message']);
        $this->assertStringContainsString('Photos that are not theirs', $mail);
        $this->assertStringContainsString('cannot switch it back on yourself', $mail);
        $this->assertSame('activity.suspended', $notification->toFcm($providerUser)['data']['type']);
    }

    public function test_a_lift_says_whether_the_activity_is_live_again(): void
    {
        [$live, $providerUser] = $this->liveActivity();
        [$paused] = $this->liveActivity('inactive');
        $service = app(ActivityPublishingService::class);

        foreach ([[$live, 'It is live again'], [$paused, 'still off the site until you activate it']] as [$activity, $expected]) {
            $service->suspend($activity, 'A reason', $this->admin);
            $lifted = $service->lift($activity->refresh());

            $this->assertStringContainsString($expected, (new ActivitySuspensionLiftedNotification($lifted))->toArray($providerUser)['message']);
        }
    }

    public function test_a_provider_is_not_told_about_their_own_publishing_or_pausing(): void
    {
        NotificationFacade::fake();
        [$draft, $providerUser] = $this->liveActivity('draft');
        $service = app(ActivityPublishingService::class);

        $service->requestPublication($draft);
        $service->pause($draft->refresh());
        $service->activate($draft->refresh());

        NotificationFacade::assertNothingSent();
    }
}
