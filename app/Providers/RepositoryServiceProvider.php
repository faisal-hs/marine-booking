<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\Category;
use App\Models\FeatureDefinition;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\FeatureDefinitionPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositoryBindings();
    }

    protected function registerRepositoryBindings(): void
    {
        $this->app->bind(
            \App\Repositories\Contracts\UserRepositoryInterface::class,
            \App\Repositories\Eloquent\UserRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ProviderRepositoryInterface::class,
            \App\Repositories\Eloquent\ProviderRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\CustomerRepositoryInterface::class,
            \App\Repositories\Eloquent\CustomerRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\CategoryRepositoryInterface::class,
            \App\Repositories\Eloquent\CategoryRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ActivityRepositoryInterface::class,
            \App\Repositories\Eloquent\ActivityRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\FeatureDefinitionRepositoryInterface::class,
            \App\Repositories\Eloquent\FeatureDefinitionRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ActivityFeatureValueRepositoryInterface::class,
            \App\Repositories\Eloquent\ActivityFeatureValueRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ScheduleRepositoryInterface::class,
            \App\Repositories\Eloquent\ScheduleRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\AvailabilityOverrideRepositoryInterface::class,
            \App\Repositories\Eloquent\AvailabilityOverrideRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ActivityBookingOptionRepositoryInterface::class,
            \App\Repositories\Eloquent\ActivityBookingOptionRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ActivityPricingRuleRepositoryInterface::class,
            \App\Repositories\Eloquent\ActivityPricingRuleRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\ReservationRepositoryInterface::class,
            \App\Repositories\Eloquent\ReservationRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\PaymentRepositoryInterface::class,
            \App\Repositories\Eloquent\PaymentRepository::class
        );
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(FeatureDefinition::class, FeatureDefinitionPolicy::class);
        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
