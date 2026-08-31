<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;

interface CustomerRepositoryInterface extends BaseRepositoryInterface
{
    public function findByUserId(int $userId): ?Customer;
}
