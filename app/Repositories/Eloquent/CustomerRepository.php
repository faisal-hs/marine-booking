<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with('user')->withCount(['reservations', 'reviews', 'favorites']);

        // Deleted accounts are hidden unless asked for. The customer row itself is never
        // deleted, only its user (see Customer::user()).
        $account = $filters['account'] ?? null;

        if ($account === 'deleted') {
            $query->whereHas('user', fn ($q) => $q->onlyTrashed());
        } else {
            $query->whereHas('user', function ($q) use ($account) {
                $q->whereNull('users.deleted_at');

                if (in_array($account, ['active', 'suspended'], true)) {
                    $q->where('users.status', $account);
                }
            });
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';

            $query->whereHas('user', fn ($u) => $u->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('email', 'like', $term));
        }

        return $query->latest()->paginate($perPage)->withQueryString();
    }
}
