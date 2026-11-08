<?php

namespace Tests\Feature\Notifications;

use App\Models\ProviderDocument;
use App\Notifications\Concerns\QueuedDelivery;
use App\Notifications\Providers\Admin\ProviderDocumentUploadedAdminNotification;
use App\Notifications\Providers\ProviderDocumentReviewedNotification;
use App\Services\NotificationFeed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/** What a provider is told about a decision on a document, and what the reviewing admins are told about an upload. */
class ProviderDocumentNotificationsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    public function test_the_provider_is_told_on_every_channel_and_the_notification_is_queued_with_the_shared_retry_policy(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $notification = new ProviderDocumentReviewedNotification($provider, 'approved', 'Safety certification');

        $this->assertSame(['database', 'fcm', 'mail'], $notification->via($user));
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertContains(QueuedDelivery::class, class_uses_recursive($notification), 'It carries the delivery policy every external notification shares');
    }

    public function test_each_outcome_says_what_happened_and_why(): void
    {
        [$user, $provider] = $this->makeProvider('verified');

        $approved = new ProviderDocumentReviewedNotification($provider, 'approved', 'Safety certification');
        $rejected = new ProviderDocumentReviewedNotification($provider, 'rejected', 'Safety certification', 'The document is not legible');
        $update = new ProviderDocumentReviewedNotification($provider, 'update_requested', 'Safety certification', 'The business name changed');

        $this->assertSame(['provider.document.approved', 'Your Safety certification was approved.'], [$approved->toArray($user)['type'], $approved->toArray($user)['message']]);
        $this->assertSame('provider.document.rejected', $rejected->toArray($user)['type']);
        $this->assertStringContainsString('was rejected', $rejected->toArray($user)['message']);
        $this->assertStringContainsString('The document is not legible', $rejected->toArray($user)['message']);
        $this->assertStringContainsString('Please upload a new one', $rejected->toArray($user)['message']);
        $this->assertSame('provider.document.update_requested', $update->toArray($user)['type']);
        $this->assertStringContainsString('asked you to update your Safety certification', $update->toArray($user)['message']);
        $this->assertStringContainsString('The business name changed', $update->toArray($user)['message']);
        $this->assertSame('The business name changed', $update->toArray($user)['note']);
    }

    public function test_the_mail_and_the_push_carry_the_same_sentence(): void
    {
        [$user, $provider] = $this->makeProvider('verified');
        $notification = new ProviderDocumentReviewedNotification($provider, 'rejected', 'Insurance certificate', 'It has expired');

        $mail = $notification->toMail($user);
        $push = $notification->toFcm($user);

        $this->assertStringContainsString('It has expired', implode(' ', array_map('strval', $mail->introLines)));
        $this->assertStringContainsString("Hi {$user->name}", $mail->greeting);
        $this->assertSame('provider.document.rejected', $push['data']['type']);
        $this->assertStringContainsString('It has expired', $push['body']);
    }

    public function test_an_admin_is_told_about_an_upload_in_the_app_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [, $provider] = $this->makeProvider('verified');
        $document = new ProviderDocument(['type' => 'safety_certification']);
        $notification = new ProviderDocumentUploadedAdminNotification($provider, $document);

        $data = $notification->toArray($this->makeAdmin());

        $this->assertSame(['database'], $notification->via($this->makeAdmin()));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification, 'Admin notifications are database-only, so queueing them would add a worker dependency for nothing');
        $this->assertSame(['admin.provider_document.uploaded', $provider->uuid, 'safety_certification'], [$data['type'], $data['provider_uuid'], $data['document_type']]);
        $this->assertStringContainsString($provider->business_name, $data['message']);
    }

    public function test_the_provider_notification_is_one_the_provider_app_feed_shows_to_providers_only(): void
    {
        $this->assertContains(ProviderDocumentReviewedNotification::class, (new \ReflectionClassConstant(NotificationFeed::class, 'PROVIDER_ONLY'))->getValue());
    }
}
