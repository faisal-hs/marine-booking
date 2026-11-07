<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesSeededBulkDatabase;
use Tests\TestCase;

/** The bulk provider profile changes: believable history, consistent with the live data, and visible in the app. */
class BulkProviderProfileChangeSeederTest extends TestCase
{
    use UsesSeededBulkDatabase;

    private function assertNone($rows, string $rule): void
    {
        $rows = collect($rows);

        $this->assertTrue($rows->isEmpty(), "{$rule}. {$rows->count()} row(s) break it, for example: ".$rows->take(3)->map(fn ($r) => json_encode($r))->implode(' | '));
    }

    public function test_every_state_is_present(): void
    {
        $states = DB::table('provider_profile_changes')->distinct()->pluck('status')->sort()->values()->all();

        $this->assertSame(['approved', 'pending', 'rejected'], $states);
    }

    public function test_each_decision_is_complete_and_a_waiting_request_has_none(): void
    {
        $this->assertNone(DB::table('provider_profile_changes')->where('status', 'approved')->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereNull('reviewed_by_user_id')->orWhereNotNull('rejection_reason'))->limit(3)->pluck('uuid'), 'An approved request has a reviewer and a time, and no rejection reason');
        $this->assertNone(DB::table('provider_profile_changes')->where('status', 'rejected')->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereNull('reviewed_by_user_id')->orWhereNull('rejection_reason'))->limit(3)->pluck('uuid'), 'A rejected request has a reviewer, a time and the reason');
        $this->assertNone(DB::table('provider_profile_changes')->where('status', 'pending')->where(fn ($q) => $q->whereNotNull('reviewed_at')->orWhereNotNull('reviewed_by_user_id')->orWhereNotNull('rejection_reason'))->limit(3)->pluck('uuid'), 'A waiting request has not been decided');
        $this->assertNone(DB::table('provider_profile_changes')->whereNotNull('reviewed_at')->whereColumn('reviewed_at', '<', 'created_at')->limit(3)->pluck('uuid'), 'Decided before it was asked');
    }

    public function test_there_is_never_more_than_one_waiting_request_per_provider(): void
    {
        $this->assertNone(DB::table('provider_profile_changes')->where('status', 'pending')->groupBy('provider_id')->havingRaw('COUNT(*) > 1')->limit(3)->pluck('provider_id'), 'Two requests waiting for the same provider');
    }

    public function test_requests_belong_to_approved_providers_and_were_asked_by_their_own_account(): void
    {
        $this->assertNone(DB::table('provider_profile_changes as c')->join('providers as p', 'p.id', '=', 'c.provider_id')->where(fn ($q) => $q->where('p.verification_status', '!=', 'verified')->orWhereColumn('c.requested_by_user_id', '!=', 'p.user_id'))->limit(3)->pluck('c.uuid'), 'An approved provider, asking through their own account');
        $this->assertNone(DB::table('provider_profile_changes as c')->join('providers as p', 'p.id', '=', 'c.provider_id')->whereColumn('c.created_at', '<', 'p.created_at')->limit(3)->pluck('c.uuid'), 'Asked before the provider existed');
    }

    public function test_the_content_matches_the_live_profile(): void
    {
        $wrong = [];

        foreach (DB::table('provider_profile_changes as c')->join('providers as p', 'p.id', '=', 'c.provider_id')->get(['c.uuid', 'c.status', 'c.requested_changes', 'p.business_name']) as $row) {
            $change = json_decode($row->requested_changes, true)['business_name'] ?? null;

            $ok = $change !== null && $change['from'] !== $change['to']
                // An approved name IS the provider's name now; a waiting or rejected one was asked from the name the provider has.
                && ($row->status === 'approved' ? $change['to'] === $row->business_name : $change['from'] === $row->business_name);

            $ok || $wrong[] = ['uuid' => $row->uuid, 'status' => $row->status];
        }

        $this->assertNone($wrong, 'An approved change ends at the live name, any other starts from it');
    }

    public function test_a_provider_sees_the_waiting_change_in_the_app(): void
    {
        $row = DB::table('provider_profile_changes as c')->join('providers as p', 'p.id', '=', 'c.provider_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('c.status', 'pending')->where('u.status', 'active')->whereNull('u.deleted_at')->orderBy('c.id')->first(['c.requested_changes', 'u.id as user_id', 'p.business_name']);
        $this->assertNotNull($row, 'There is a waiting change to look at');

        $token = User::findOrFail($row->user_id)->createToken('t', ['provider'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])->getJson('/api/v1/provider/profile')->assertOk();

        $this->assertSame($row->business_name, $response->json('data.business_name'), 'The live name is unchanged');
        $this->assertSame(json_decode($row->requested_changes, true)['business_name']['to'], $response->json('data.pending_profile_change.changes.business_name.requested'));
    }
}
