<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Common data-access contract every concrete repository implements.
 * Repositories are responsible ONLY for persistence — no business logic,
 * no workflow decisions (that belongs in Services).
 */
interface BaseRepositoryInterface
{
    public function all(array $columns = ['*']): Collection;

    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator;

    public function find(int|string $id, array $columns = ['*']): ?Model;

    public function findOrFail(int|string $id, array $columns = ['*']): Model;

    public function findByUuid(string $uuid, array $columns = ['*']): ?Model;

    public function create(array $attributes): Model;

    public function update(Model $model, array $attributes): Model;

    public function delete(Model $model): bool;

    /**
     * Apply an arbitrary set of filters (e.g. from a request's query params).
     * Concrete repositories define what filter keys they support.
     */
    public function filter(array $filters): self;
}
