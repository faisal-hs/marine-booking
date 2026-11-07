<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;

/**
 * Requests to change a provider's business name, among the approved providers: about one in eight has an approved one in its past
 * (the approved name is the provider's CURRENT name, and the old one was its previous name), one in twenty a rejected one with the admin's
 * reason, and one in thirty-three a request waiting right now (at most one per provider, as the app allows).
 * The first three approved providers always get one of each state (approved, rejected, waiting), so even a small database shows every state.
 * Volume at scale 1: about 370.
 * Prerequisites: BulkProviderSeeder, BulkAdminSeeder (the reviewers).
 */
class BulkProviderProfileChangeSeeder extends BulkSeeder
{
    use BulkPools;

    private const REJECTION_REASONS = [
        'The name does not match your commercial registration.',
        'Please send the updated commercial registration first.',
        'This name is too close to another provider already on the platform.',
        'The name contains wording we cannot accept.',
    ];

    protected function seed(): void
    {
        $this->needs('providers', 'BulkProviderSeeder', fn ($q) => $q->where('verification_status', 'verified'));

        $reviewers = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', ['super_admin', 'admin'])
            ->pluck('model_has_roles.model_id')->all();
        $have = array_flip(DB::table('provider_profile_changes')->distinct()->pluck('provider_id')->all());

        $providers = DB::table('providers')
            ->join('users', 'users.id', '=', 'providers.user_id')
            ->where(fn ($q) => $q->where('users.phone', 'like', self::PROVIDER_PHONE_PREFIX.'%')->orWhere('users.phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%'))
            ->where('providers.verification_status', 'verified')
            ->orderBy('providers.id')
            ->get(['providers.id', 'providers.user_id', 'providers.business_name', 'providers.created_at']);

        $this->insert('provider_profile_changes', (function () use ($providers, $have, $reviewers) {
            foreach ($providers as $position => $provider) {
                if (isset($have[$provider->id])) {
                    continue;
                }

                $joined = Carbon::parse($provider->created_at);

                if ($position === 0 || $this->stable("profile-change-approved:{$provider->id}", 12)) {
                    $asked = $joined->copy()->addDays($this->rand(30, 120));
                    $asked = $asked->greaterThan($this->now->copy()->subDays(3)) ? $this->now->copy()->subDays(3) : $asked;

                    yield $this->decided($provider, 1, 'approved', $asked, "{$provider->business_name} (previous name)", $provider->business_name, null, $reviewers);
                }

                if ($position === 1 || $this->stable("profile-change-rejected:{$provider->id}", 5)) {
                    $asked = $this->daysAgo(15, 90);
                    $asked = $asked->lessThan($joined) ? $joined->copy() : $asked;

                    yield $this->decided($provider, 2, 'rejected', $asked, $provider->business_name, "{$provider->business_name} Group", $this->pick(self::REJECTION_REASONS), $reviewers);
                }

                if ($position === 2 || $this->stable("profile-change-pending:{$provider->id}", 3)) {
                    $asked = $this->daysAgo(0, 12);
                    $asked = $asked->lessThan($joined) ? $joined->copy() : $asked;

                    yield $this->row($provider, 3, 'pending', $asked, $provider->business_name, "{$provider->business_name} International", null, null, null);
                }
            }
        })());
    }

    private function decided(object $provider, int $n, string $status, Carbon $asked, string $from, string $to, ?string $reason, array $reviewers): array
    {
        $reviewed = $asked->copy()->addDays($this->rand(1, 4));
        $reviewed = $reviewed->greaterThan($this->now) ? $this->now->copy() : $reviewed;

        return $this->row($provider, $n, $status, $asked, $from, $to, $reason, $reviewers ? $this->pick($reviewers) : null, $reviewed);
    }

    private function row(object $provider, int $n, string $status, Carbon $asked, string $from, string $to, ?string $reason, ?int $reviewer, ?Carbon $reviewed): array
    {
        return [
            'uuid' => $this->uuid('profile-change', "{$provider->id}-{$n}"),
            'provider_id' => $provider->id,
            'requested_by_user_id' => $provider->user_id,
            'requested_changes' => json_encode(['business_name' => ['from' => $from, 'to' => $to]], JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'rejection_reason' => $reason,
            'reviewed_by_user_id' => $reviewer,
            'reviewed_at' => $reviewed ? $this->ts($reviewed) : null,
            'created_at' => $this->ts($asked),
            'updated_at' => $this->ts($reviewed ?? $asked),
        ];
    }
}
