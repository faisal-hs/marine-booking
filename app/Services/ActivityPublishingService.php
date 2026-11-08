<?php

namespace App\Services;

use App\Events\ActivitySuspended;
use App\Events\ActivitySuspensionLifted;
use App\Models\Activity;
use App\Models\User;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Who can make an activity live, and who can stop it.
 *
 *   The ADMIN approves the provider account only; nobody approves an activity. An approved provider creates, edits and publishes their own.
 *   PROVIDER   requestPublication()  a draft goes live at once, or is held as `approved` (ready, hidden) until the provider activates it:
 *                                    the platform setting ACTIVITY_AUTO_PUBLISH (config/activities.php)
 *              activate()            turns it on, always live: from a draft, from ready, or from the provider's own pause
 *              pause()               takes a live activity off the site
 *   ADMIN      suspend()             stops any activity (reason mandatory), recorded APART from the provider's pause
 *              lift()                the only way back from a suspension
 *
 * A suspended activity is `inactive` like a paused one, but with suspended_at set, and EVERY way of making an activity live refuses it: a provider
 * can never switch a suspension off, however they ask.
 */
class ActivityPublishingService
{
    public function __construct(
        protected ActivityRepositoryInterface $repository,
        protected ProviderVerificationGate $gate,
    ) {}

    /** The provider's request to publish a draft. */
    public function requestPublication(Activity $activity): Activity
    {
        // A suspension is answered as what it is, whatever state the activity is in.
        $this->assertNotSuspended($activity);

        if ($activity->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ["Only a draft can be published this way; this activity is {$activity->status}."]]);
        }

        $this->assertMayGoLive($activity);

        return config('activities.auto_publish') ? $this->goLive($activity) : $this->repository->update($activity, ['status' => 'approved']);
    }

    /** The provider turning an activity on: it goes live whatever ACTIVITY_AUTO_PUBLISH says. */
    public function activate(Activity $activity): Activity
    {
        if (! in_array($activity->status, ['draft', 'approved', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => ["An activity that is {$activity->status} cannot be activated."]]);
        }

        $this->assertMayGoLive($activity);

        return $this->goLive($activity);
    }

    /** The provider taking a live activity off the site. */
    public function pause(Activity $activity): Activity
    {
        if ($activity->status !== 'published') {
            throw ValidationException::withMessages(['status' => ['Only a published activity can be paused.']]);
        }

        return $this->repository->update($activity, ['status' => 'inactive']);
    }

    /** An admin stopping an activity that is live, waiting to go live, or paused. */
    public function suspend(Activity $activity, string $reason, User $admin): Activity
    {
        if ($activity->isSuspended()) {
            throw ValidationException::withMessages(['status' => ['This activity is already suspended.']]);
        }

        if (! in_array($activity->status, ['published', 'approved', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => ["Only a published, ready or paused activity can be suspended; this one is {$activity->status}."]]);
        }

        $activity = $this->repository->update($activity, [
            'status' => 'inactive',
            'suspended_at' => now(),
            'suspension_reason' => $reason,
            'suspended_by_user_id' => $admin->id,
            'suspended_from_status' => $activity->status,
        ]);

        event(new ActivitySuspended($activity));

        return $activity;
    }

    /** An admin ending a suspension: the activity goes back to what it was (live again only if its provider is still in good standing). */
    public function lift(Activity $activity): Activity
    {
        if (! $activity->isSuspended()) {
            throw ValidationException::withMessages(['status' => ['This activity is not suspended.']]);
        }

        $restore = match ($activity->suspended_from_status) {
            'published' => $this->providerMayPublish($activity) ? 'published' : 'inactive',
            'approved' => 'approved',
            default => 'inactive',
        };

        $activity = $this->repository->update($activity, [
            'status' => $restore,
            'published_at' => $restore === 'published' ? ($activity->published_at ?? now()) : $activity->published_at,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_by_user_id' => null,
            'suspended_from_status' => null,
        ]);

        event(new ActivitySuspensionLifted($activity));

        return $activity;
    }

    private function goLive(Activity $activity): Activity
    {
        return $this->repository->update($activity, ['status' => 'published', 'published_at' => $activity->published_at ?? now()]);
    }

    /** The one place every way of going live passes through. */
    private function assertMayGoLive(Activity $activity): void
    {
        $this->assertNotSuspended($activity);

        $this->gate->assertCanPublish($activity->provider);
    }

    private function assertNotSuspended(Activity $activity): void
    {
        if ($activity->isSuspended()) {
            throw new AuthorizationException('This activity was suspended by an admin. Only an admin can lift the suspension.');
        }
    }

    private function providerMayPublish(Activity $activity): bool
    {
        try {
            $this->gate->assertCanPublish($activity->provider);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
