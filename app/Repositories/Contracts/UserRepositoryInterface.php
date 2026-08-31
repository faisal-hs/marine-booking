<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findByPhone(string $phone): ?User;

    public function emailExists(string $email): bool;

    public function phoneExists(string $phone): bool;

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator;
}
