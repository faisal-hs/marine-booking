<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->newQuery()->where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->model->newQuery()->where('phone', $phone)->first();
    }

    public function emailExists(string $email): bool
    {
        return $this->model->newQuery()->where('email', $email)->exists();
    }

    public function phoneExists(string $phone): bool
    {
        return $this->model->newQuery()->whereNotNull('phone')->where('phone', $phone)->exists();
    }
}
