<?php

namespace App\Services\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Models\Activity;
use App\Models\Provider;
use App\Services\ActivityPublishingService;
use App\Support\ProviderApi\ErrorCode;

/**
 * PATCH /provider/activities/{id}/status: the provider turning their own activity on (`active`) or off (`paused`).
 *
 *   active   a draft, a ready activity (publish requested while ACTIVITY_AUTO_PUBLISH is off) or one the provider paused goes live. Always live:
 *            this is the provider's own decision. An activity an ADMIN suspended is refused, whatever the status it shows.
 *   paused   a live activity comes off the site.
 */
class ProviderActivityStatus
{
    public function __construct(private readonly ActivityPublishingService $publishing) {}

    public function change(Provider $provider, string $activityId, string $to): Activity
    {
        $activity = ctype_digit($activityId) ? Activity::query()->where('provider_id', $provider->id)->find((int) $activityId) : null;

        if (! $activity) {
            throw ProviderApiException::notFound('Activity not found.');
        }

        if ($activity->isSuspended()) {
            throw new ProviderApiException('This activity was suspended by an admin. Only an admin can lift the suspension.', 403, ErrorCode::ACTIVITY_SUSPENDED_BY_ADMIN);
        }

        if ($to === 'active') {
            return match ($activity->status) {
                'draft', 'approved', 'inactive' => $this->publishing->activate($activity),
                'published' => throw ProviderApiException::conflict('This activity is already active.', ErrorCode::INVALID_STATUS_TRANSITION),
                default => throw ProviderApiException::conflict("This activity is {$activity->status} and cannot be activated.", ErrorCode::INVALID_STATUS_TRANSITION),
            };
        }

        if ($activity->status !== 'published') {
            throw ProviderApiException::conflict("Only an active activity can be paused; this one is {$activity->status}.", ErrorCode::INVALID_STATUS_TRANSITION);
        }

        return $this->publishing->pause($activity);
    }

    /** The provider app's word for each state. `ready` = publish was requested while automatic publishing is off: hidden until the provider activates it. */
    public static function label(string $status): string
    {
        return match ($status) {
            'published' => 'active',
            'inactive' => 'paused',
            'approved' => 'ready',
            default => $status,
        };
    }
}
