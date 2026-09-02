<?php

namespace App\Http\Controllers\Api\Provider;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Provider\PricingRule\StorePricingRuleRequest;
use App\Http\Requests\Provider\PricingRule\UpdatePricingRuleRequest;
use App\Http\Resources\PricingRule\ActivityPricingRuleResource;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivityPricingRule;
use App\Services\ActivityPricingRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ActivityPricingRuleController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(protected ActivityPricingRuleService $service) {}

    public function index(Activity $activity, ActivityBookingOption $bookingOption)
    {
        $this->authorize('view', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);

        return $this->success(ActivityPricingRuleResource::collection($bookingOption->pricingRules));
    }

    public function store(StorePricingRuleRequest $request, Activity $activity, ActivityBookingOption $bookingOption)
    {
        $this->authorize('update', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);

        $rule = $this->service->create($bookingOption, $request->payload());

        return $this->created(new ActivityPricingRuleResource($rule), 'Pricing rule created successfully.');
    }

    public function update(UpdatePricingRuleRequest $request, Activity $activity, ActivityBookingOption $bookingOption, ActivityPricingRule $pricingRule)
    {
        $this->authorize('update', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);
        $this->abortIfRuleDoesNotBelongToOption($bookingOption, $pricingRule);

        $rule = $this->service->update($pricingRule, $request->payload());

        return $this->success(new ActivityPricingRuleResource($rule), 'Pricing rule updated successfully.');
    }

    public function destroy(Activity $activity, ActivityBookingOption $bookingOption, ActivityPricingRule $pricingRule)
    {
        $this->authorize('update', $activity);
        $this->abortIfOptionDoesNotBelongToActivity($activity, $bookingOption);
        $this->abortIfRuleDoesNotBelongToOption($bookingOption, $pricingRule);

        $this->service->delete($pricingRule);

        return $this->noContent('Pricing rule deleted successfully.');
    }

    protected function abortIfOptionDoesNotBelongToActivity(Activity $activity, ActivityBookingOption $bookingOption): void
    {
        abort_if($bookingOption->activity_id !== $activity->id, 404);
    }

    /**
     * A second, independent ownership check — a pricing rule must belong
     * to THIS SPECIFIC booking option, not just to some option on the
     * right activity. Without this, a provider could pass a pricing rule
     * uuid from a sibling booking option on the same activity and act on
     * it via the wrong option's URL.
     */
    protected function abortIfRuleDoesNotBelongToOption(ActivityBookingOption $bookingOption, ActivityPricingRule $pricingRule): void
    {
        abort_if($pricingRule->activity_booking_option_id !== $bookingOption->id, 404);
    }
}
