<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReviewRepositoryInterface extends BaseRepositoryInterface
{
    public function existsForCustomerAndActivity(int $customerId, int $activityId): bool;

    /**
     * Visible (non-hidden) reviews only — this is the PUBLIC-facing
     * listing used on an activity's detail page.
     */
    public function forActivity(int $activityId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Unrestricted — includes hidden reviews, for admin moderation.
     */
    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;
}
