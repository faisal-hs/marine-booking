<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findByPhone(string $phone): ?User;

    public function emailExists(string $email): bool;

    public function phoneExists(string $phone): bool;
}
