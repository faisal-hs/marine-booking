<?php

namespace Database\Seeders;

use App\Models\ProviderRequest;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * An application for every provider that has none. A provider signs in and asks `GET /provider/apply`, so every provider that can sign in must have an application to
 * show: the provider app answers "you have not submitted an application yet" otherwise. The demo providers were created without one (the sign-up flow makes one, the
 * seeders that create providers directly did not), so this seeder gives each the application its state implies:
 *
 *   verified     an APPROVED application, reviewed a little after it was submitted, by an admin
 *   pending      a PENDING application, waiting for review (no reviewer, no reviewed time)
 *   rejected     a REJECTED application, with the provider's own rejection reason (or a plain one), reviewed by an admin
 *
 * An application is submitted when the provider was created (never in the future; a provider made just now, as in the demo data, has its decided application dated up to a day and a half back, so the review comes after it), as `registration` for a provider account and `customer_upgrade` for a customer who became
 * a provider, as the app itself records them. It only fills the GAPS: a provider that already has an application, of any status, is left exactly as it is, so running it
 * again adds nothing, and it is safe on an installation that has real applications. (Unlike the demo seeders it is not refused in production: what it adds is only the
 * application a provider's own state already implies.)
 *
 *     php artisan db:seed --class=ProviderApplicationsSeeder
 *
 * DatabaseSeeder runs it right after the demo providers are made. The bulk providers are deliberately NOT all given one by BulkProviderRequestSeeder (about one in seven
 * pending ones has none, to have a "not submitted yet" case): run this seeder after the bulk seeders to give those one too.
 */
class ProviderApplicationsSeeder extends Seeder
{
    private const DEFAULT_REJECTION = 'The submitted documents could not be verified. Please check them and apply again.';

    public function run(): void
    {
        $reviewer = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->orderBy('model_has_roles.model_id')->value('model_has_roles.model_id');
        $now = Carbon::now();
        $made = ['approved' => 0, 'pending' => 0, 'rejected' => 0];

        $lacking = DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')->whereNull('p.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('provider_requests as r')->whereColumn('r.provider_id', 'p.id'))
            ->orderBy('p.id')->get(['p.id', 'p.user_id', 'p.verification_status', 'p.rejection_reason', 'p.created_at', 'u.user_type']);

        foreach ($lacking as $provider) {
            $submitted = Carbon::parse($provider->created_at)->min($now);
            $status = match ($provider->verification_status) {
                'verified' => 'approved',
                'rejected' => 'rejected',
                default => 'pending',
            };
            $reviewed = null;

            if ($status !== 'pending') {
                $delay = 2 + $provider->id % 30;
                $reviewed = $submitted->copy()->addHours($delay);

                // A provider made moments ago (the demo data) cannot have been reviewed in the future: the application is then dated far enough back for the review to come
                // after it. An older provider is not touched by this: its application is submitted when it was created, and reviewed a few hours later.
                if ($reviewed->greaterThan($now)) {
                    $reviewed = $now->copy();
                    $submitted = $now->copy()->subHours($delay);
                }
            }

            (new ProviderRequest)->forceFill([
                'user_id' => $provider->user_id, 'provider_id' => $provider->id,
                'source' => $provider->user_type === 'provider' ? ProviderRequest::SOURCE_REGISTRATION : ProviderRequest::SOURCE_CUSTOMER_UPGRADE,
                'status' => $status, 'submitted_at' => $submitted, 'reviewed_at' => $reviewed, 'reviewed_by' => $status === 'pending' ? null : $reviewer,
                'rejection_reason' => $status === 'rejected' ? ($provider->rejection_reason ?: self::DEFAULT_REJECTION) : null,
                'created_at' => $submitted, 'updated_at' => $reviewed ?? $submitted,
            ])->save();

            $made[$status]++;
        }

        $this->command?->line(sprintf('Applications: %d made (%d approved, %d pending, %d rejected); %d providers already had one.', array_sum($made), $made['approved'], $made['pending'], $made['rejected'],
            DB::table('providers')->whereNull('deleted_at')->count() - $lacking->count()));
    }
}
