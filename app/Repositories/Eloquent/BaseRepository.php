<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\BaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    /**
     * The query builder instance, reset after each terminal operation
     * so filters don't leak between unrelated calls on the same repository instance.
     */
    protected Builder $query;

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->resetQuery();
    }

    protected function resetQuery(): void
    {
        $this->query = $this->model->newQuery();
    }

    public function all(array $columns = ['*']): Collection
    {
        $result = $this->query->get($columns);
        $this->resetQuery();

        return $result;
    }

    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        $result = $this->query->paginate($perPage, $columns);
        $this->resetQuery();

        return $result;
    }

    public function find(int|string $id, array $columns = ['*']): ?Model
    {
        return $this->model->newQuery()->find($id, $columns);
    }

    public function findOrFail(int|string $id, array $columns = ['*']): Model
    {
        return $this->model->newQuery()->findOrFail($id, $columns);
    }

    public function findByUuid(string $uuid, array $columns = ['*']): ?Model
    {
        return $this->model->newQuery()->where('uuid', $uuid)->first($columns);
    }

    public function create(array $attributes): Model
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->update($attributes);

        return $model->refresh();
    }

    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * Base no-op filter — concrete repositories override this to apply
     * their own supported filter keys, then call parent-built query as needed.
     */
    public function filter(array $filters): static
    {
        return $this;
    }
}
