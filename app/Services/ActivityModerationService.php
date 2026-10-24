<?php

namespace App\Services;

use App\Events\ActivityApproved;
use App\Events\ActivityPublished;
use App\Events\ActivityRejected;
use App\Events\ActivitySentBackToReview;
use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Validation\ValidationException;

class ActivityModerationService
{
    public function __construct(
        protected ActivityRepositoryInterface $repository,
        protected ProviderVerificationGate $verificationGate,
    ) {}

    public function approve(Activity $activity): Activity
    {
        if ($activity->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => ['Only activities pending review can be approved.'],
            ]);
        }

        $this->verificationGate->assertVerifiedForApproval($activity->provider);

        $activity = $this->repository->update($activity, [
            'status' => 'published',
            'published_at' => now(),
            'rejection_reason' => null,
        ]);

        event(new ActivityApproved($activity));

        return $activity;
    }

    public function reject(Activity $activity, string $reason): Activity
    {
        if ($activity->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => ['Only activities pending review can be rejected.'],
            ]);
        }

        $activity = $this->repository->update($activity, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        event(new ActivityRejected($activity));

        return $activity;
    }

    /**
     * Reactivates a previously deactivated activity. Deliberately does not
     * reset published_at — that timestamp records when the activity was
     * FIRST published, not when it was most recently toggled visible.
     */
    public function publish(Activity $activity): Activity
    {
        if ($activity->status !== 'inactive') {
            throw ValidationException::withMessages([
                'status' => ['Only inactive activities can be republished this way.'],
            ]);
        }

        // Republishing puts the activity in front of customers again, so it needs
        // the same verified-provider check as a first approval.
        $this->verificationGate->assertVerifiedForApproval($activity->provider);

        $activity = $this->repository->update($activity, ['status' => 'published']);

        event(new ActivityPublished($activity));

        return $activity;
    }

    public function deactivate(Activity $activity): Activity
    {
        if ($activity->status !== 'published') {
            throw ValidationException::withMessages([
                'status' => ['Only published activities can be deactivated.'],
            ]);
        }

        return $this->repository->update($activity, ['status' => 'inactive']);
    }

    /**
     * Takes a LIVE activity off the public site until an admin approves it again.
     * Used when a provider changes content customers rely on to decide (see
     * LiveActivityContentGuard). The first publication date is kept, which is also
     * how the admin screens tell a re-review from a brand new submission.
     *
     * Idempotent: anything that is not currently published is returned untouched.
     */
    public function sendBackToReview(Activity $activity): Activity
    {
        if ($activity->status !== 'published') {
            return $activity;
        }

        $activity = $this->repository->update($activity, ['status' => 'pending_review']);

        event(new ActivitySentBackToReview($activity));

        return $activity;
    }
}
