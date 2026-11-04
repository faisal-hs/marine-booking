<?php

namespace App\Repositories\Eloquent;

use App\Models\Provider;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProviderRepository extends BaseRepository implements ProviderRepositoryInterface
{
    public function __construct(Provider $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Provider
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
    }

    public function findByUserId(int $userId): ?Provider
    {
        return $this->model->newQuery()->where('user_id', $userId)->first();
    }

    public function slugExists(string $slug): bool
    {
        // withTrashed(): a deleted row still owns its slug (the unique index counts it); see ActivityRepository::slugExists.
        return $this->model->newQuery()->withTrashed()->where('slug', $slug)->exists();
    }

    public function uniqueSlug(string $businessName): string
    {
        $base = \Illuminate\Support\Str::slug($businessName) ?: 'provider';
        $slug = $base;

        for ($suffix = 1; $this->slugExists($slug); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    public function forSelect(int $limit = 500): \Illuminate\Support\Collection
    {
        return $this->model->newQuery()
            ->whereHas('user', fn($q) => $q->whereNull('users.deleted_at')->where('users.status', 'active'))
            ->orderBy('business_name')
            ->limit($limit)
            ->get(['id', 'business_name']);
    }

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with('user')->withCount(['documents', 'activities']);

        if (! empty($filters['status'])) {
            $query->where('verification_status', $filters['status']);
        }

        // Account state. Deleted accounts are hidden unless asked for ("deleted"); the
        // provider row itself is never deleted, only its user is (see Provider::user()).
        $account = $filters['account'] ?? null;

        if ($account === 'deleted') {
            $query->whereHas('user', fn($q) => $q->onlyTrashed());
        } else {
            $query->whereHas('user', function ($q) use ($account) {
                $q->whereNull('users.deleted_at');

                if (in_array($account, ['active', 'suspended'], true)) {
                    $q->where('users.status', $account);
                }
            });
        }

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('business_name', 'like', $term)
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }

        return $query->latest()->paginate($perPage)->withQueryString();
    }
}
