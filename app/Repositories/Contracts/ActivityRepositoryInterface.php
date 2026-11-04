<?php

namespace App\Repositories\Contracts;

use App\Models\Activity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ActivityRepositoryInterface extends BaseRepositoryInterface
{
    public function findBySlug(string $slug): ?Activity;

    public function findByIdOrUuidOrSlug(string $value): ?Activity;

    /**
     * Sets every PUBLISHED activity of the provider to "inactive" so customers can no longer
     * book it. Returns how many were changed.
     */
    public function deactivatePublishedForProvider(int $providerId): int;

    public function slugExists(string $slug, ?int $ignoreId = null): bool;

    public function paginateForProvider(int $providerId, array $filters, int $perPage = 15): LengthAwarePaginator;

    public function paginatePublished(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function featured(int $limit = 8);

    public function topRated(int $limit = 8);
}
