<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface ProviderDocumentRepositoryInterface extends BaseRepositoryInterface
{
    public function forProvider(int $providerId): Collection;

    public function countForProvider(int $providerId): int;
}
