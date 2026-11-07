<?php

namespace App\Services\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Models\Activity;
use App\Models\Provider;
use App\Services\ActivityModerationService;
use App\Support\ProviderApi\ErrorCode;

/**
 * The provider turns an activity on or off. It never replaces the admin's approval: an activity that has not been approved cannot be
 * turned on, whatever the provider asks.
 *
 *   active   approved (waiting to be published)  -> published      the provider's own launch, when automatic publishing is off
 *            paused                              -> published      resume
 *   paused   published                           -> paused         hidden from customers; bookings already confirmed are NOT touched
 *
 * The platform's "inactive" and the spec's "paused" are the same state, shown to the app as "paused", and published is shown as "active".
 */
class ProviderActivityStatus
{
    public function __construct(private readonly ActivityModerationService $moderation) {}

    public function change(Provider $provider, string $activityId, string $to): Activity
    {
        $activity = ctype_digit($activityId) ? Activity::query()->where('provider_id', $provider->id)->find((int) $activityId) : null;

        if (! $activity) {
            throw ProviderApiException::notFound('Activity not found.');
        }

        if ($to === 'active') {
            return match ($activity->status) {
                'approved' => $this->moderation->publishApproved($activity),
                'inactive' => $this->moderation->publish($activity),
                'published' => throw ProviderApiException::conflict('This activity is already active.', ErrorCode::INVALID_STATUS_TRANSITION),
                default => throw ProviderApiException::conflict("This activity is {$activity->status} and needs an admin's approval before it can be published.", ErrorCode::INVALID_STATUS_TRANSITION),
            };
        }

        if ($activity->status !== 'published') {
            throw ProviderApiException::conflict("Only an active activity can be paused; this one is {$activity->status}.", ErrorCode::INVALID_STATUS_TRANSITION);
        }

        return $this->moderation->deactivate($activity);
    }

    /** The status as the provider app names it. */
    public static function label(string $status): string
    {
        return match ($status) {
            'published' => 'active',
            'inactive' => 'paused',
            default => $status,
        };
    }
}
