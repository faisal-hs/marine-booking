<?php

namespace App\Services;

use App\Models\Activity;

/**
 * Runs a provider's edit and, if it changed what customers see on a LIVE
 * activity, sends the activity back to review (taking it off the public site
 * until an admin approves it again).
 *
 * "Changed" is decided by comparing a fingerprint of the customer-visible
 * content before and after the edit, NOT by noticing that something was
 * written. The combined update replaces whole sets (features, requirements,
 * inclusions) with the same values on every save, so an app that re-sends an
 * unchanged form must not unpublish the listing.
 *
 * Content that triggers a re-review: title, description, category, location
 * (location, meeting point, coordinates), images (files, order, cover), feature
 * values, requirements, inclusions and exclusions.
 *
 * Deliberately NOT content: schedules, blackout dates, booking options and
 * prices, paid extras, participant limits and duration. Providers change those
 * day to day (closing a date, adjusting a price), and a customer's price is
 * frozen on their reservation when they book.
 *
 * Only a published activity is guarded. Drafts, activities already in review and
 * rejected ones are the provider's to edit freely.
 */
class LiveActivityContentGuard
{
    public function __construct(protected ActivityModerationService $moderation) {}

    public function run(Activity $activity, callable $edit): mixed
    {
        if ($activity->status !== 'published') {
            return $edit();
        }

        $before = $this->fingerprint($activity);

        $result = $edit();

        $current = $activity->fresh();

        if ($current !== null && $this->fingerprint($current) !== $before) {
            $this->moderation->sendBackToReview($current);
        }

        // Callers build their response from these instances, so they must show the
        // status the database holds now, not the one from before the edit.
        $activity->refresh();

        if ($result instanceof Activity && ! $result->is($activity)) {
            $result->refresh();
        }

        return $result;
    }

    public function fingerprint(Activity $activity): string
    {
        $payload = [
            'core' => [
                'title' => $activity->title,
                'description' => $activity->description,
                'category_id' => $activity->category_id,
                'location' => $activity->location,
                'meeting_point' => $activity->meeting_point,
                'lat' => $activity->lat === null ? null : (float) $activity->lat,
                'lng' => $activity->lng === null ? null : (float) $activity->lng,
            ],
            'features' => $activity->featureValues()->with('featureDefinition')->orderBy('feature_definition_id')->get()
                ->map(fn($value) => [$value->feature_definition_id, $value->value])->all(),
            'requirements' => $activity->requirements()->get()
                ->map(fn($requirement) => [$requirement->text, (bool) $requirement->is_mandatory])->all(),
            'inclusions' => $activity->inclusions()->get()
                ->map(fn($inclusion) => [$inclusion->type, $inclusion->description])->all(),
            'images' => $activity->images()->get()
                ->map(fn($image) => [$image->path, (bool) $image->is_cover])->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
