<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Binds every Repository interface to its Eloquent implementation.
 * As each module is built, its interface/implementation pair is registered here.
 *
 * Bindings are registered explicitly in register() (not via Laravel's magic
 * $bindings property) to avoid colliding with ServiceProvider's own reserved
 * property name, and to keep bindings easy to trace during code review.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositoryBindings();
    }

    protected function registerRepositoryBindings(): void
    {
        // $this->app->bind(
        //     \App\Repositories\Contracts\ActivityRepositoryInterface::class,
        //     \App\Repositories\Eloquent\ActivityRepository::class
        // );
        //
        // $this->app->bind(
        //     \App\Repositories\Contracts\ReservationRepositoryInterface::class,
        //     \App\Repositories\Eloquent\ReservationRepository::class
        // );
        //
        // ... populated module by module, starting with Module 01 (Authentication).
    }

    public function boot(): void
    {
        //
    }
}
