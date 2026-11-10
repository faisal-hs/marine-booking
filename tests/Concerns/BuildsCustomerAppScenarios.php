<?php

namespace Tests\Concerns;

use App\Models\Activity;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Scenarios for the customer app. Today is Sunday 15 November 2026 (12:00 UTC, 15:00 in Riyadh); the activities run on Saturdays at 09:00 and cost 1,500
 * for an adult, so the next departure is Saturday 21 November.
 */
trait BuildsCustomerAppScenarios
{
    use BuildsProviderAppScenarios;

    protected const NEXT_SATURDAY = '2026-11-21';

    protected function pinTime(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
    }

    /** One call to the customer app. */
    protected function ca(?string $token, string $method, string $path, array $data = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($headers + ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []))->json($method, '/api/v1/customer-app'.$path, $data);
    }

    /** Multipart. */
    protected function caFiles(?string $token, string $path, array $data, array $headers = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($headers + ['Accept' => 'application/json'] + ($token ? ['Authorization' => 'Bearer '.$token] : []))->post('/api/v1/customer-app'.$path, $data);
    }

    /** @return array{0: User, 1: Customer, 2: string} a customer, signed in */
    protected function signedInCustomer(array $userAttributes = []): array
    {
        [$user, $customer] = $this->makeCustomer($userAttributes + ['phone_verified_at' => now()]);

        return [$user, $customer, $this->tokenFor($user, 'customer')];
    }

    protected function makeCity(string $en = 'Jeddah', string $ar = 'جدة', array $attrs = []): City
    {
        return City::create($attrs + ['name_en' => $en, 'name_ar' => $ar, 'slug' => Str::slug($en).'-'.uniqid()]);
    }

    /** A second activity of the same provider: bookable, with its own price. */
    protected function moreActivity(array $scenario, string $title, float $price, array $attrs = []): Activity
    {
        [$activity, $option] = $this->makeBookableActivity($scenario['provider'], 'published', ['title' => $title] + $attrs);
        $option->pricingRules()->update(['price' => $price]);

        return $activity;
    }

    protected function withCover(Activity $activity, string $path = 'activities/cover.jpg'): Activity
    {
        $activity->images()->create(['path' => $path, 'sort_order' => 0, 'is_cover' => true]);

        return $activity;
    }

    protected function category(string $name): Category
    {
        return $this->makeCategory($name);
    }
}
