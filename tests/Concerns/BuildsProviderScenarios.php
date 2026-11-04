<?php

namespace Tests\Concerns;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
            'slug' => 'test-diving-' . uniqid(),
            'verification_status' => $verification,
            'rejection_reason' => $verification === 'rejected' ? 'Licence photo was unreadable' : null,
        ]);

        return [$user, $provider, $user->createToken('test', ['provider'])->plainTextToken];
    }

    protected function makeCategory(string $name = 'Diving'): Category
    {
        return Category::create(['name' => $name, 'slug' => strtolower($name) . '-' . uniqid(), 'status' => 'active']);
    }

    protected function makeActivity(Provider $provider, string $status = 'draft', array $attributes = []): Activity
    {
        $category = $attributes['category'] ?? $this->makeCategory();
        unset($attributes['category']);

        return Activity::create(array_merge([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Reef Dive',
            'slug' => 'reef-dive-' . uniqid(),
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
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * A published (or other status) activity that can actually be booked: a per-person
     * option priced at 1500 per adult. Returns [activity, booking option].
     *
     * @return array{0: Activity, 1: ActivityBookingOption}
     */
    protected function makeBookableActivity(Provider $provider, string $status = 'published', array $attributes = []): array
    {
        $activity = $this->makeActivity($provider, $status, $attributes);
        $activity->schedulePeriods()->create(['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '13:00']);
        $option = $activity->bookingOptions()->create(['name' => 'Standard', 'slug' => 'standard-' . uniqid(), 'booking_model' => 'per_person']);
        $option->pricingRules()->create(['participant_type' => 'adult', 'price' => 1500]);

        return [$activity, $option];
    }

    /** @return array{0: User, 1: Customer} */
    protected function makeCustomer(array $userAttributes = []): array
    {
        $user = User::factory()->customer()->create($userAttributes);

        return [$user, Customer::create(['user_id' => $user->id])];
    }

    /** A pending booking with its 15-minute hold. $date defaults to the next Saturday. */
    protected function bookActivity(Customer $customer, Activity $activity, ActivityBookingOption $option, ?Carbon $date = null): Reservation
    {
        return app(ReservationService::class)->create(
            $customer,
            $activity,
            $option,
            null,
            $date ?? Carbon::now()->startOfDay()->next(Carbon::SATURDAY),
            ['adult' => 1]
        );
    }

    /** Pays through the mock gateway: the booking becomes confirmed. */
    protected function payFor(Reservation $reservation): Payment
    {
        return app(PaymentService::class)->initiate($reservation, ['simulate' => 'sync_success'])['payment'];
    }

    /** An in-app notification entry for $user, made directly so a test controls exactly what it holds. */
    protected function giveNotification(User $user, array $data = ['message' => 'Something happened'], string $type = 'App\\Notifications\\Reservations\\Admin\\NewReservationAdminNotification', bool $read = false, ?Carbon $at = null): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => $data,
            'read_at' => $read ? now() : null,
            'created_at' => $at ?? now(),
            'updated_at' => $at ?? now(),
        ]);
    }

    /**
     * One person with BOTH accounts: registered as a customer, with a provider account in the given
     * state ('verified' means an admin approved their request). Returns [user, customer, provider].
     *
     * @return array{0: User, 1: Customer, 2: Provider}
     */
    protected function makeDualAccount(string $providerStatus = 'verified'): array
    {
        [$user, $customer] = $this->makeCustomer();
        $provider = Provider::create([
            'user_id' => $user->id,
            'business_name' => 'Dual Divers',
            'slug' => 'dual-divers-' . uniqid(),
            'city' => 'Jeddah',
            'area' => 'North Obhur',
            'verification_status' => $providerStatus,
        ]);

        return [$user, $customer, $provider];
    }

    /** A bearer token signed into one account (the ability is "customer" or "provider"). */
    protected function tokenFor(User $user, string $account): string
    {
        return $user->createToken('test', [$account])->plainTextToken;
    }

    /** A request carrying a real token, without the leftovers of a previous call in the same test. */
    protected function asAccount(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']);
    }

    /** What the app sends to ask to become a provider: the details plus the three documents. */
    protected function providerRequestPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Jeddah Divers',
            'city' => 'Jeddah',
            'area' => 'North Obhur',
            'commercial_registration' => UploadedFile::fake()->create('cr.pdf', 50, 'application/pdf'),
            'safety_certification' => UploadedFile::fake()->create('safety.pdf', 50, 'application/pdf'),
            'insurance_certificate' => UploadedFile::fake()->create('insurance.pdf', 50, 'application/pdf'),
        ], $overrides);
    }
}
