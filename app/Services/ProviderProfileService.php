<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderProfileChange;
use App\Models\User;
use App\Notifications\Providers\Admin\ProviderProfileChangeRequestedAdminNotification;
use App\Notifications\Providers\ProviderProfileChangeReviewedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * A provider changes their profile. Most of it changes at once; CRITICAL information (config/provider_app.php: the legal business name and the
 * business type) does not take effect until an admin approves it, and the live profile keeps its current value meanwhile.
 *
 *   - a critical field sent with its current value is no change at all;
 *   - there is at most one request waiting: asking again REPLACES it (the older one is kept as "superseded"), and asking for a field's
 *     current value again withdraws just that field from the waiting request (the whole request is "cancelled" if nothing is left);
 *   - approving applies what was asked; rejecting leaves the profile as it is, and the provider is told why.
 *
 * The same rule serves PATCH /provider/profile and the older PUT: the policy is about the information, not the verb.
 */
class ProviderProfileService
{
    private const PROVIDER_FIELDS = ['business_name', 'business_type', 'marina_name', 'contact_phone', 'contact_email', 'bio', 'address', 'city', 'area', 'lat', 'lng'];

    public function __construct(private readonly NotificationDispatchService $dispatcher) {}

    /**
     * @param  array<string, mixed>  $data  ONLY the fields to change: a key that is present is a change, even to null
     * @return array{provider: Provider, pending: ProviderProfileChange|null, waiting: list<string>}
     */
    public function update(User $user, array $data): array
    {
        $provider = $user->provider;
        $critical = (array) config('provider_app.critical_profile_fields');
        $requested = [];
        $now = [];

        foreach (self::PROVIDER_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = is_string($data[$field]) ? trim($data[$field]) : $data[$field];

            if (in_array($field, $critical, true)) {
                $requested[$field] = $value;
            } else {
                $now[$field] = $value;
            }
        }

        $pending = DB::transaction(function () use ($user, $provider, $data, $now, $requested) {
            $account = array_intersect_key($data, array_flip(['name', 'email']));
            if ($account !== []) {
                $user->update($account);
            }
            if ($now !== []) {
                $provider->update($now);
            }
            if (array_key_exists('category_ids', $data)) {
                $provider->categories()->sync($data['category_ids']);
            }

            return $this->requestCritical($user, $provider, $requested);
        });

        return ['provider' => $provider->refresh(), 'pending' => $pending, 'waiting' => $pending ? $pending->fields() : []];
    }

    /** @param  array<string, mixed>  $requested  critical field => the value asked for */
    private function requestCritical(User $user, Provider $provider, array $requested): ?ProviderProfileChange
    {
        $waiting = $provider->pendingProfileChange()->first();

        if ($requested === []) {
            return $waiting;
        }

        // What is wanted now: whatever was already waiting, with the new asks on top.
        $wanted = collect($waiting?->requested_changes ?? [])->map(fn (array $c) => $c['to'])->all();
        foreach ($requested as $field => $value) {
            $wanted[$field] = $value;
        }

        // A field asked to be what it already is, is not a change (and withdraws it from a waiting request).
        $wanted = array_filter($wanted, fn ($to, $field) => $to !== $provider->{$field}, ARRAY_FILTER_USE_BOTH);

        $changes = [];
        foreach ($wanted as $field => $to) {
            $changes[$field] = ['from' => $provider->{$field}, 'to' => $to];
        }

        if ($changes === []) {
            $waiting?->update(['status' => ProviderProfileChange::CANCELLED]);

            return null;
        }

        if ($waiting && $waiting->requested_changes == $changes) {
            return $waiting;
        }

        $waiting?->update(['status' => ProviderProfileChange::SUPERSEDED]);

        $change = $provider->profileChanges()->create(['requested_by_user_id' => $user->id, 'requested_changes' => $changes, 'status' => ProviderProfileChange::PENDING]);

        foreach ($this->admins() as $admin) {
            $this->dispatcher->dispatchOnce("admin.provider_profile_change.requested:{$change->id}", $admin, new ProviderProfileChangeRequestedAdminNotification($change));
        }

        return $change;
    }

    public function approve(ProviderProfileChange $change, User $reviewer): ProviderProfileChange
    {
        return $this->decide($change, $reviewer, ProviderProfileChange::APPROVED, null);
    }

    public function reject(ProviderProfileChange $change, User $reviewer, string $reason): ProviderProfileChange
    {
        return $this->decide($change, $reviewer, ProviderProfileChange::REJECTED, $reason);
    }

    private function decide(ProviderProfileChange $change, User $reviewer, string $status, ?string $reason): ProviderProfileChange
    {
        $change = DB::transaction(function () use ($change, $reviewer, $status, $reason) {
            // Locked, so two admins cannot decide the same request.
            $change = ProviderProfileChange::query()->lockForUpdate()->findOrFail($change->id);

            if (! $change->isPending()) {
                throw ValidationException::withMessages(['change' => ['This change has already been decided or replaced.']]);
            }

            if ($status === ProviderProfileChange::APPROVED) {
                $change->provider->update(collect($change->requested_changes)->map(fn (array $c) => $c['to'])->all());
            }

            $change->update(['status' => $status, 'rejection_reason' => $reason, 'reviewed_by_user_id' => $reviewer->id, 'reviewed_at' => now()]);

            return $change;
        });

        $this->dispatcher->dispatchOnce("provider.profile_change.reviewed:{$change->id}", $change->provider->user, new ProviderProfileChangeReviewedNotification($change));

        return $change;
    }

    /** The admins who manage providers. */
    private function admins()
    {
        try {
            return User::permission('providers.update')->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }
}
