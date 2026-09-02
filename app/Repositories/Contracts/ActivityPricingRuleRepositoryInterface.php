<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface ActivityPricingRuleRepositoryInterface extends BaseRepositoryInterface
{
    public function forBookingOption(int $bookingOptionId): Collection;

    public function activeForBookingOption(int $bookingOptionId): Collection;
}
