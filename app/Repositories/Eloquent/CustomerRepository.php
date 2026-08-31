<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;

class CustomerRepository extends BaseRepository implements CustomerRepositoryInterface
{
    public function __construct(Customer $model)
    {
        parent::__construct($model);
    }

    public function findByUserId(int $userId): ?Customer
    {
        return $this->model->newQuery()->where('user_id', $userId)->first();
    }
}
