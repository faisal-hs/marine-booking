<?php

namespace App\Repositories\Contracts;

use App\Models\Provider;

interface ProviderRepositoryInterface extends BaseRepositoryInterface
{
    public function findBySlug(string $slug): ?Provider;

    public function findByUserId(int $userId): ?Provider;

    public function slugExists(string $slug): bool;
}
