<?php

namespace App\Providers;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\ProviderRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositoryBindings();
    }

    protected function registerRepositoryBindings(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(ProviderRepositoryInterface::class, ProviderRepository::class);
        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
