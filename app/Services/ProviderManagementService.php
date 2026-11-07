<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Provider;
use App\Models\Reservation;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin-side management of provider accounts: create, edit, suspend, delete, restore.
 *
 * Every one of these has a consequence beyond the account row, which is why this is
 * its own service and not the generic user form:
 *
 *  - SUSPENDING or DELETING takes the provider's published activities off the public
 *    site (they become "inactive"); otherwise customers could keep booking activities
 *    nobody will serve. Reactivating or restoring does NOT republish them: an admin does
 *    that deliberately, and the verified-provider check runs again when they do.
 *  - DELETING is refused while a booking is live (see ActiveBookingsGuard).
 *  - It only ever soft-deletes the USER; the provider row stays so reservations,
 *    payments and reviews keep their history (see Provider::user()).
 */
class ProviderManagementService
{
    public function __construct(
        protected ProviderRepositoryInterface $providers,
        protected UserRepositoryInterface $users,
        protected ActivityRepositoryInterface $activities,
        protected ReservationRepositoryInterface $reservations,
        protected ProviderVerificationService $verification,
        protected ActiveBookingsGuard $bookingsGuard,
    ) {}

    /**
     * An account created on the provider's behalf, bypassing OTP signup. It has no
     * password (providers sign in by phone and one-time code), so the owner simply logs
     * in with the phone number the admin entered.
     *
     * @param  bool  $verifyNow  mark it verified straight away. Goes through the normal
     *                           verification service, so the owner is notified exactly as
     *                           when an admin verifies them later.
     */
    public function create(array $data, bool $verifyNow = false): Provider
    {
        $provider = DB::transaction(function () use ($data) {
            $user = $this->users->create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'user_type' => 'provider',
                'status' => 'active',
            ]);

            return $this->providers->create([
                'user_id' => $user->id,
                'business_name' => $data['business_name'],
                'slug' => $this->generateUniqueSlug($data['business_name']),
                'business_license' => $data['business_license'] ?? null,
                'bio' => $data['bio'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'area' => $data['area'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'commission_rate' => $data['commission_rate'] ?? 0,
                'commission_type' => $data['commission_type'] ?? 'percentage',
            ]);
        });

        if ($verifyNow) {
            $this->verification->verify($provider);
        }

        return $provider->fresh('user');
    }

    /** Edits the business profile and the owner's contact details. Verification and account status have their own actions. */
    public function update(Provider $provider, array $data): Provider
    {
        DB::transaction(function () use ($provider, $data) {
            $contact = array_intersect_key($data, array_flip(['name', 'email', 'phone']));
            if ($contact !== []) {
                $provider->user->update($contact);
            }

            $profile = array_intersect_key($data, array_flip(['business_name', 'business_license', 'bio', 'address', 'city', 'area', 'lat', 'lng', 'commission_rate', 'commission_type']));
            // The columns are not nullable: an empty value means "leave it as it is".
            foreach (['commission_rate', 'commission_type'] as $column) {
                if (array_key_exists($column, $profile) && $profile[$column] === null) {
                    unset($profile[$column]);
                }
            }
            if ($profile !== []) {
                $this->providers->update($provider, $profile);
            }
        });

        return $provider->fresh('user');
    }

    public function suspend(Provider $provider): void
    {
        if ($provider->user->status === 'suspended') {
            return;
        }

        DB::transaction(function () use ($provider) {
            $provider->user->update(['status' => 'suspended']);
            // A suspended provider loses access at once, not when the token expires.
            $provider->user->tokens()->delete();
            $this->activities->deactivatePublishedForProvider($provider->id);
        });
    }

    public function reactivate(Provider $provider): void
    {
        if ($provider->user->status === 'active') {
            return;
        }

        $provider->user->update(['status' => 'active']);
    }

    public function delete(Provider $provider): void
    {
        if ($provider->user->trashed()) {
            return;
        }

        $this->bookingsGuard->assertNoneForProvider($provider);

        // Deleting the PERSON deletes both of their accounts, so their bookings as a customer count too.
        if ($customer = $provider->user->customer) {
            $this->bookingsGuard->assertNoneForCustomer($customer);
        }

        DB::transaction(function () use ($provider) {
            $this->activities->deactivatePublishedForProvider($provider->id);
            $provider->user->tokens()->delete();
            $provider->user->delete(); // soft delete, per §22 — never hard-delete user records
        });
    }

    public function restore(Provider $provider): void
    {
        if ($provider->user->trashed()) {
            $provider->user->restore();
        }
    }

    /**
     * The numbers on the provider page. Revenue uses the same definition as the Reports
     * module (a payment that succeeded, even if later partly or fully refunded), so the
     * two screens never disagree.
     *
     * @return array{activities: array<string, int>, bookings_total: int, bookings_live: int, gross_revenue: float}
     */
    public function summary(Provider $provider): array
    {
        $allActivityIds = $provider->activities()->withTrashed()->pluck('id')->all();

        return [
            'activities' => $provider->activities()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn($n) => (int) $n)->all(),
            'bookings_total' => $allActivityIds === [] ? 0 : Reservation::whereIn('activity_id', $allActivityIds)->count(),
            'bookings_live' => $allActivityIds === [] ? 0 : $this->reservations->countLiveBookings($allActivityIds),
            'gross_revenue' => $allActivityIds === [] ? 0.0 : (float) Payment::whereIn('status', ReportService::SUCCEEDED_STATUSES)
                ->whereHas('reservation', fn($q) => $q->whereIn('activity_id', $allActivityIds))
                ->sum('amount'),
        ];
    }

    protected function generateUniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName) ?: 'provider';
        $slug = $base;
        $suffix = 1;

        while ($this->providers->slugExists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
