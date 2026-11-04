<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Payment;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side management of customer accounts: create, edit, suspend, delete, restore.
 *
 * Deleting only ever soft-deletes the USER. The customer row stays, because their
 * reservations, reviews and favorites all reference it with ON DELETE CASCADE:
 * removing it would silently destroy booking and payment history. A delete is also
 * refused while the customer has a live booking (see ActiveBookingsGuard).
 */
class CustomerManagementService
{
    public function __construct(
        protected CustomerRepositoryInterface $customers,
        protected UserRepositoryInterface $users,
        protected ReservationRepositoryInterface $reservations,
        protected ActiveBookingsGuard $bookingsGuard,
        protected \App\Repositories\Contracts\ActivityRepositoryInterface $activities,
    ) {}

    /**
     * An account created on the customer's behalf, bypassing OTP signup. There is no
     * password: customers sign in by phone and a one-time code.
     */
    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $user = $this->users->create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'user_type' => 'customer',
                'status' => 'active',
            ]);

            return $this->customers->create([
                'user_id' => $user->id,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? 'en',
            ])->load('user');
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        DB::transaction(function () use ($customer, $data) {
            $contact = array_intersect_key($data, array_flip(['name', 'email', 'phone']));
            if ($contact !== []) {
                $customer->user->update($contact);
            }

            $profile = array_intersect_key($data, array_flip(['date_of_birth', 'preferred_language']));
            // The language column is not nullable: an empty value means "leave it as it is".
            if (array_key_exists('preferred_language', $profile) && $profile['preferred_language'] === null) {
                unset($profile['preferred_language']);
            }
            if ($profile !== []) {
                $this->customers->update($customer, $profile);
            }
        });

        return $customer->fresh('user');
    }

    public function suspend(Customer $customer): void
    {
        if ($customer->user->status === 'suspended') {
            return;
        }

        DB::transaction(function () use ($customer) {
            $customer->user->update(['status' => 'suspended']);
            // Signed out at once, not when the token expires.
            $customer->user->tokens()->delete();
            // Suspending the PERSON suspends both of their accounts: a provider's listings come off the site.
            if ($provider = $customer->user->provider) {
                $this->activities->deactivatePublishedForProvider($provider->id);
            }
        });
    }

    public function reactivate(Customer $customer): void
    {
        if ($customer->user->status !== 'active') {
            $customer->user->update(['status' => 'active']);
        }
    }

    public function delete(Customer $customer): void
    {
        if ($customer->user->trashed()) {
            return;
        }

        $this->bookingsGuard->assertNoneForCustomer($customer);

        // Deleting the PERSON deletes both of their accounts, so bookings on the provider side count too.
        $provider = $customer->user->provider;
        if ($provider) {
            $this->bookingsGuard->assertNoneForProvider($provider);
        }

        DB::transaction(function () use ($customer, $provider) {
            if ($provider) {
                $this->activities->deactivatePublishedForProvider($provider->id);
            }
            $customer->user->tokens()->delete();
            $customer->user->delete(); // soft delete, per §22 — never hard-delete user records
        });
    }

    public function restore(Customer $customer): void
    {
        if ($customer->user->trashed()) {
            $customer->user->restore();
        }
    }

    /**
     * The numbers on the customer page. "Paid" uses the Reports definition of revenue (a
     * payment that succeeded, even if later partly or fully refunded).
     *
     * @return array{bookings_total: int, bookings_live: int, reviews: int, favorites: int, total_paid: float}
     */
    public function summary(Customer $customer): array
    {
        return [
            'bookings_total' => $customer->reservations()->count(),
            'bookings_live' => $this->reservations->countLiveBookings(null, $customer->id),
            'reviews' => $customer->reviews()->count(),
            'favorites' => $customer->favorites()->count(),
            'total_paid' => (float) Payment::whereIn('status', ReportService::SUCCEEDED_STATUSES)
                ->whereHas('reservation', fn($q) => $q->where('customer_id', $customer->id))
                ->sum('amount'),
        ];
    }
}
