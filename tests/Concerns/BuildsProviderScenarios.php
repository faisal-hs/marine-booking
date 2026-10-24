<?php

namespace Tests\Concerns;

use App\Models\Activity;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;

/**
 * Fixtures for the provider onboarding and moderation tests. Dates are not involved,
 * so nothing here depends on the calendar.
 */
trait BuildsProviderScenarios
{
    /** @return array{0: User, 1: Provider, 2: string} [user, provider profile, bearer token] */
    protected function makeProvider(string $verification = 'verified'): array
    {
        $user = User::factory()->provider()->create();

        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Test Diving',
            'slug' => 'test-diving-'.uniqid(),
            'verification_status' => $verification,
            'rejection_reason' => $verification === 'rejected' ? 'Licence photo was unreadable' : null,
        ]);

        return [$user, $provider, $user->createToken('test', ['provider'])->plainTextToken];
    }

    protected function makeCategory(string $name = 'Diving'): Category
    {
        return Category::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active']);
    }

    protected function makeActivity(Provider $provider, string $status = 'draft', array $attributes = []): Activity
    {
        $category = $attributes['category'] ?? $this->makeCategory();
        unset($attributes['category']);

        return Activity::create(array_merge([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Reef Dive',
            'slug' => 'reef-dive-'.uniqid(),
            'description' => 'A guided dive on the reef.',
            'location' => 'Hurghada',
            'meeting_point' => 'Marina dock 1',
            'status' => $status,
            'published_at' => in_array($status, ['published', 'inactive'], true) ? now() : null,
        ], $attributes));
    }

    protected function makeAdmin(string $role = 'super_admin'): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Test']);
        $user->syncRoles([$role]);

        return $user;
    }

    protected function asProvider(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
