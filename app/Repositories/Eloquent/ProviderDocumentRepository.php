<?php

namespace App\Repositories\Eloquent;

use App\Models\ProviderDocument;
use App\Repositories\Contracts\ProviderDocumentRepositoryInterface;
use Illuminate\Support\Collection;

class ProviderDocumentRepository extends BaseRepository implements ProviderDocumentRepositoryInterface
{
    public function __construct(ProviderDocument $model)
    {
        parent::__construct($model);
    }

    public function forProvider(int $providerId): Collection
    {
        return $this->model->newQuery()
            ->where('provider_id', $providerId)
            ->latest()
            ->latest('id')
            ->get();
    }

    public function countForProvider(int $providerId): int
    {
        return $this->model->newQuery()->where('provider_id', $providerId)->count();
    }
}
