<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FavoriteRepositoryInterface extends BaseRepositoryInterface
{
    public function exists(int $customerId, int $activityId): bool;

    public function forCustomer(int $customerId, int $perPage = 15): LengthAwarePaginator;
}
