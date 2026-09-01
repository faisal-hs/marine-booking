<?php

namespace App\Services;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Validation\ValidationException;

class ActivityModerationService
{
    public function __construct(protected ActivityRepositoryInterface $repository) {}

    public function approve(Activity $activity): Activity
    {
        if ($activity->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => ['Only activities pending review can be approved.'],
            ]);
        }

        return $this->repository->update($activity, [
            'status' => 'published',
            'published_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function reject(Activity $activity, string $reason): Activity
    {
        if ($activity->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => ['Only activities pending review can be rejected.'],
            ]);
        }

        return $this->repository->update($activity, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
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

        return $this->repository->update($activity, ['status' => 'published']);
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
}
