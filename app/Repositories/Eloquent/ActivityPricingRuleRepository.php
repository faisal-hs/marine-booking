<?php

namespace App\Repositories\Eloquent;

use App\Models\ActivityPricingRule;
use App\Repositories\Contracts\ActivityPricingRuleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ActivityPricingRuleRepository extends BaseRepository implements ActivityPricingRuleRepositoryInterface
{
    public function __construct(ActivityPricingRule $model)
    {
        parent::__construct($model);
    }

    public function forBookingOption(int $bookingOptionId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_booking_option_id', $bookingOptionId)
            ->get();
    }

    public function activeForBookingOption(int $bookingOptionId): Collection
    {
        return $this->model->newQuery()
            ->where('activity_booking_option_id', $bookingOptionId)
            ->where('is_active', true)
            ->get();
    }
}
