<?php

namespace App\Services;

use App\Models\Activity;
use App\Services\CustomerApp\ActivityCatalog;
use App\Support\ReservationType;
use Illuminate\Validation\ValidationException;

/**
 * WHY a customer can or cannot see an activity, and what to do about it. The provider app shows this on every activity (`customer_visibility`), so an activity that does not
 * appear to customers or in their search is never a mystery.
 *
 * Customers see an activity when it is `published`, not suspended, and has a default booking option with a PRICE (an adult price for a booking by persons; one price for the
 * others): see ActivityCatalog::visible(), which is the one rule, and the answer `is_visible` is taken from it. The `problems` only EXPLAIN a false answer, one for each thing that
 * is wrong, in the order to put them right:
 *
 *   suspended, paused, provider_not_verified, draft, ready_not_activated      (the activity is not live)
 *   no_booking_option, no_price                                               (it cannot be priced, so it is hidden)
 *   no_schedule                                                               (it is seen, but nothing can be booked: `blocks` is `booking`)
 */
class ActivityVisibility
{
    public function __construct(private readonly ActivityCatalog $catalog, private readonly ProviderVerificationGate $gate) {}

    /**
     * @return array{is_visible: bool, is_bookable: bool, problems: list<array{code: string, message: string, blocks: string}>}
     */
    public function of(Activity $activity): array
    {
        $visible = $this->catalog->visible()->whereKey($activity->id)->exists();
        $problems = [];

        if ($activity->isSuspended()) {
            $problems[] = $this->problem('suspended', 'An admin suspended this activity'.($activity->suspension_reason ? ": {$activity->suspension_reason}" : '').'. Only an admin can lift the suspension.');
        } elseif ($activity->status === 'inactive') {
            $problems[] = $this->problem('paused', 'You paused this activity. Activate it to put it back on the site.');
        } elseif ($activity->status === 'approved') {
            $problems[] = $this->problem('ready_not_activated', 'It is ready but hidden until you activate it.');
        } elseif ($activity->status === 'draft') {
            $problems[] = $this->whyDraft($activity);
        }

        $default = $this->catalog->defaultOption($activity);

        if ($default === null) {
            $problems[] = $this->problem('no_booking_option', 'It has no booking option. Add one (how it is booked) with a price: customers only see an activity they can book.');
        } elseif (! Activity::query()->whereKey($activity->id)->whereRaw(ActivityCatalog::PRICE_SQL.' IS NOT NULL')->exists()) {
            $problems[] = $this->problem('no_price', ReservationType::of($default->booking_model) === ReservationType::PERSONS
                ? "Its booking option \"{$default->name}\" has no adult price. Add an adult price: customers only see an activity that has a price."
                : "Its booking option \"{$default->name}\" has no price. Add one: customers only see an activity that has a price.");
        }

        $scheduled = $activity->schedulePeriods()->where('status', 'active')->exists();

        if (! $scheduled) {
            $problems[] = $this->problem('no_schedule', 'It has no schedule (the days and times it runs), so nothing can be booked yet.', 'booking');
        }

        return ['is_visible' => $visible, 'is_bookable' => $visible && $scheduled, 'problems' => $problems];
    }

    /** A draft is held for one of three reasons, and the provider is told which. */
    private function whyDraft(Activity $activity): array
    {
        try {
            $this->gate->assertCanPublish($activity->provider);
        } catch (ValidationException $e) {
            return $this->problem('provider_not_verified', collect($e->errors())->flatten()->first());
        }

        return config('activities.auto_publish')
            ? $this->problem('draft', 'It is a draft. Publish it when it is ready.')
            : $this->problem('draft', 'It is a draft. Activities are not published automatically here: publish it when it is ready.');
    }

    /** @return array{code: string, message: string, blocks: string} */
    private function problem(string $code, string $message, string $blocks = 'visibility'): array
    {
        return ['code' => $code, 'message' => $message, 'blocks' => $blocks];
    }
}
