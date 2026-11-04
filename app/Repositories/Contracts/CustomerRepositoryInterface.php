<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerRepositoryInterface extends BaseRepositoryInterface
{
    public function findByUserId(int $userId): ?Customer;

    /**
     * The admin list. Filters: search (name, phone, email), account (active, suspended,
     * deleted; deleted accounts are hidden unless asked for).
     */
    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;
}
