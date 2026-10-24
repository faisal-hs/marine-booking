<?php

namespace Tests\Feature\Notifications;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\Activities\ActivityApprovedNotification;
use App\Notifications\Activities\ActivityPublishedNotification;
use App\Notifications\Activities\ActivityRejectedNotification;
use App\Services\ActivityModerationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class ActivityModerationNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeAdmin(string $role): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function makePendingActivity(): array
    {
        $providerUser = User::factory()->provider()->create();
        $provider = Provider::create(['user_id' => $providerUser->id, 'business_name' => 'Test Diving', 'slug' => 'test-diving-' . uniqid(), 'verification_status' => 'verified']);
        $category = Category::create(['name' => 'Diving', 'slug' => 'diving-' . uniqid(), 'status' => 'active']);
        $activity = Activity::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Test Activity',
            'slug' => 'test-activity-' . uniqid(),
            'status' => 'pending_review',
        ]);

        return [$activity, $providerUser];
    }

    public function test_approving_an_activity_notifies_only_the_provider(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$activity, $providerUser] = $this->makePendingActivity();

        app(ActivityModerationService::class)->approve($activity);

        NotificationFacade::assertSentTo($providerUser, ActivityApprovedNotification::class);
        NotificationFacade::assertNothingSentTo($superAdmin);
    }

    public function test_activity_approved_channel_matrix(): void
    {
        [$activity, $providerUser] = $this->makePendingActivity();
        $notification = new ActivityApprovedNotification($activity);

        $this->assertEquals(['database', 'fcm', 'mail'], $notification->via($providerUser));
    }

    public function test_rejecting_an_activity_notifies_only_the_provider(): void
    {
        NotificationFacade::fake();
        $superAdmin = $this->makeAdmin('super_admin');
        [$activity, $providerUser] = $this->makePendingActivity();

        app(ActivityModerationService::class)->reject($activity, 'Incomplete details');

        NotificationFacade::assertSentTo($providerUser, ActivityRejectedNotification::class);
        NotificationFacade::assertNothingSentTo($superAdmin);
    }

    public function test_republishing_a_deactivated_activity_notifies_the_provider(): void
    {
        NotificationFacade::fake();
        [$activity, $providerUser] = $this->makePendingActivity();
        $activity->update(['status' => 'inactive']);

        app(ActivityModerationService::class)->publish($activity);

        NotificationFacade::assertSentTo($providerUser, ActivityPublishedNotification::class);
    }

    public function test_activity_published_channel_matrix_is_lighter_than_approved(): void
    {
        [$activity, $providerUser] = $this->makePendingActivity();
        $notification = new ActivityPublishedNotification($activity);

        $this->assertEquals(['database', 'fcm'], $notification->via($providerUser));
    }
}
