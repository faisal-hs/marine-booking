<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;

/**
 * The provider requests behind the providers: an approved one for every approved provider (a fifth of them were
 * rejected once first), a rejected one for every rejected provider, and a waiting one for most waiting providers
 * (one in seven registered but never submitted a request).
 * Volume at scale 1: about 2,500.
 * Prerequisites: BulkProviderSeeder, BulkAdminSeeder (the reviewers).
 */
class BulkProviderRequestSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('providers', 'BulkProviderSeeder');

        $reviewers = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', ['super_admin', 'admin'])
            ->pluck('model_has_roles.model_id')->all();
        $have = array_flip(DB::table('provider_requests')->distinct()->pluck('provider_id')->all());

        $providers = DB::table('providers')
            ->join('users', 'users.id', '=', 'providers.user_id')
            ->where(fn ($q) => $q->where('users.phone', 'like', self::PROVIDER_PHONE_PREFIX.'%')->orWhere('users.phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%'))
            ->orderBy('providers.id')
            ->get(['providers.id', 'providers.user_id', 'providers.verification_status', 'providers.created_at', 'users.user_type']);

        $this->insert('provider_requests', (function () use ($providers, $have, $reviewers) {
            foreach ($providers as $provider) {
                if (isset($have[$provider->id])) {
                    continue;
                }

                $source = $provider->user_type === 'provider' ? 'registration' : 'customer_upgrade';
                $submitted = \Carbon\Carbon::parse($provider->created_at)->addDays($this->rand(0, 3));

                $rows = [];
                if ($provider->verification_status === 'verified') {
                    if ($this->chance(20)) {
                        $rows[] = $this->decided($provider, 1, $source, 'rejected', $submitted->copy(), $reviewers);
                        $submitted = $submitted->copy()->addDays($this->rand(3, 10));
                    }
                    $rows[] = $this->decided($provider, 2, $source, 'approved', $submitted, $reviewers);
                } elseif ($provider->verification_status === 'rejected') {
                    $rows[] = $this->decided($provider, 1, $source, 'rejected', $submitted, $reviewers);
                } elseif ($provider->id % 7 !== 0) {
                    $waiting = $this->daysAgo(0, 20);
                    $rows[] = [
                        'uuid' => $this->uuid('provider-request', "{$provider->id}-1"),
                        'user_id' => $provider->user_id, 'provider_id' => $provider->id, 'source' => $source, 'status' => 'pending',
                        'submitted_at' => $this->ts($waiting), 'reviewed_at' => null, 'reviewed_by' => null, 'rejection_reason' => null,
                        'created_at' => $this->ts($waiting), 'updated_at' => $this->ts($waiting),
                    ];
                }

                yield from $rows;
            }
        })());
    }

    private function decided(object $provider, int $n, string $source, string $status, \Carbon\Carbon $submitted, array $reviewers): array
    {
        $submitted = $submitted->greaterThan($this->now) ? $this->now->copy()->subDays(2) : $submitted;
        $reviewed = $submitted->copy()->addDays($this->rand(1, 5));
        $reviewed = $reviewed->greaterThan($this->now) ? $this->now->copy() : $reviewed;

        return [
            'uuid' => $this->uuid('provider-request', "{$provider->id}-{$n}"),
            'user_id' => $provider->user_id, 'provider_id' => $provider->id, 'source' => $source, 'status' => $status,
            'submitted_at' => $this->ts($submitted), 'reviewed_at' => $this->ts($reviewed),
            'reviewed_by' => $reviewers ? $this->pick($reviewers) : null,
            'rejection_reason' => $status === 'rejected' ? $this->pick($this->providerRejectionReasons()) : null,
            'created_at' => $this->ts($submitted), 'updated_at' => $this->ts($reviewed),
        ];
    }
}
