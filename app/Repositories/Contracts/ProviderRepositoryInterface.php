<?php

namespace App\Repositories\Contracts;

use App\Models\Provider;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProviderRepositoryInterface extends BaseRepositoryInterface
{
    public function findBySlug(string $slug): ?Provider;

    public function findByUserId(int $userId): ?Provider;

    public function slugExists(string $slug): bool;

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;
}
