<?php

namespace Tests\Feature\Seeders;

use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ProviderApplicationsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\Concerns\UsesSeededDemoDatabase;
use Tests\TestCase;

/**
 * ProviderApplicationsSeeder gives every provider without an application the one their state implies, so GET /provider/apply has something to answer for every provider
 * that can sign in. The demo data (DatabaseSeeder, which runs it) is built once for the class; the tests that change something do it inside a transaction that is rolled back.
 */
class ProviderApplicationsSeederTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use UsesSeededDemoDatabase;

    private const APPLICATION_KEYS = ['application_id', 'status', 'note', 'submitted_at', 'reviewed_at', 'can_resubmit'];

    protected function seedDemo(): void
    {
        // The demo data already ran the seeder (DatabaseSeeder calls it): nothing more to do.
    }

    private function applications(int $providerId)
    {
        return DB::table('provider_requests')->where('provider_id', $providerId)->orderBy('id');
    }

    private function applyAs(User $user): array
    {
        $token = $this->tokenFor($user, 'provider');

        return $this->hit($token, 'GET', '/api/v1/provider/apply')->assertOk()->json('data');
    }

    private function providerWith(string $status): Provider
    {
        return Provider::where('verification_status', $status)->orderBy('id')->firstOrFail();
    }

    // --- The demo providers ---------------------------------------------------------------------------------------------

    public function test_every_demo_provider_has_exactly_one_application(): void
    {
        $this->assertGreaterThan(15, Provider::count());

        foreach (Provider::all() as $provider) {
            $this->assertSame(1, $this->applications($provider->id)->count(), "Provider {$provider->id}: {$provider->business_name}");
        }
        $this->assertSame(Provider::count(), DB::table('provider_requests')->count());
    }

    public function test_the_application_is_the_one_the_providers_state_implies(): void
    {
        $expected = ['verified' => 'approved', 'pending' => 'pending', 'rejected' => 'rejected'];

        foreach (Provider::all() as $provider) {
            $this->assertSame($expected[$provider->verification_status], $this->applications($provider->id)->value('status'), "Provider {$provider->id} is {$provider->verification_status}");
        }

        $statuses = DB::table('provider_requests')->distinct()->pluck('status')->sort()->values()->all();
        $this->assertSame(['approved', 'pending', 'rejected'], $statuses, 'The demo data has an application in each state');
    }

    public function test_an_approved_application_was_reviewed_by_an_admin_after_it_was_submitted(): void
    {
        $admins = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->whereIn('roles.name', ['super_admin', 'admin'])->pluck('model_has_roles.model_id')->all();
        $approved = DB::table('provider_requests')->where('status', 'approved');

        $this->assertGreaterThan(5, (clone $approved)->count());
        $this->assertSame(0, (clone $approved)->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereNull('reviewed_by')->orWhereColumn('reviewed_at', '<=', 'submitted_at')->orWhereNotNull('rejection_reason'))->count());
        $this->assertSame(0, (clone $approved)->whereNotIn('reviewed_by', $admins)->count());
    }

    public function test_a_pending_application_is_waiting_with_no_reviewer_and_no_reason(): void
    {
        $pending = DB::table('provider_requests')->where('status', 'pending');

        $this->assertGreaterThan(0, (clone $pending)->count());
        $this->assertSame(0, (clone $pending)->where(fn ($q) => $q->whereNotNull('reviewed_at')->orWhereNotNull('reviewed_by')->orWhereNotNull('rejection_reason'))->count());
    }

    public function test_a_rejected_application_carries_the_reason_and_the_reviewer(): void
    {
        $rejected = DB::table('provider_requests')->where('status', 'rejected');

        $this->assertGreaterThan(0, (clone $rejected)->count());
        $this->assertSame(0, (clone $rejected)->where(fn ($q) => $q->whereNull('rejection_reason')->orWhereNull('reviewed_at')->orWhereNull('reviewed_by')->orWhereColumn('reviewed_at', '<=', 'submitted_at'))->count());

        // The provider's own reason when it has one, a plain one when it has not.
        foreach (DB::table('provider_requests as r')->join('providers as p', 'p.id', '=', 'r.provider_id')->where('r.status', 'rejected')->get(['r.rejection_reason', 'p.rejection_reason as provider_reason']) as $row) {
            $this->assertSame($row->provider_reason ?: 'The submitted documents could not be verified. Please check them and apply again.', $row->rejection_reason);
        }
    }

    public function test_nothing_is_dated_in_the_future_and_every_provider_account_is_a_registration(): void
    {
        $now = $this->demoSeededAt()->addHour()->toDateTimeString();

        $this->assertSame(0, DB::table('provider_requests')->where('submitted_at', '>', $now)->count());
        $this->assertSame(0, DB::table('provider_requests')->where('reviewed_at', '>', $now)->count());
        $this->assertSame(['registration'], DB::table('provider_requests')->distinct()->pluck('source')->all());
        $this->assertSame(0, DB::table('provider_requests')->whereColumn('created_at', '!=', 'submitted_at')->count());
    }

    // --- What a provider then sees -------------------------------------------------------------------------------------------

    public function test_the_demo_provider_you_sign_in_with_gets_its_application(): void
    {
        $user = User::where('email', 'provider@marine-booking.test')->firstOrFail();

        $d = $this->applyAs($user);

        $this->assertSame(self::APPLICATION_KEYS, array_slice(array_keys($d), 0, 6), 'The application comes first, in this order');
        $this->assertSame('approved', $d['status']);
        $this->assertFalse($d['can_resubmit']);
        $this->assertNull($d['note']);
        $this->assertIsInt($d['application_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $d['submitted_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $d['reviewed_at']);
        $this->assertSame(['provider', 'stats'], array_slice(array_keys($d), 6), 'And then the provider and the statistics');
    }

    public function test_a_pending_and_a_rejected_provider_see_theirs_too(): void
    {
        $pending = $this->applyAs($this->providerWith('pending')->user);
        $this->assertSame(['pending', null, null, false], [$pending['status'], $pending['note'], $pending['reviewed_at'], $pending['can_resubmit']]);

        $rejected = $this->providerWith('rejected');
        $d = $this->applyAs($rejected->user);
        $this->assertSame(['rejected', true], [$d['status'], $d['can_resubmit']]);
        $this->assertSame($this->applications($rejected->id)->value('rejection_reason'), $d['note']);
        $this->assertNotNull($d['reviewed_at']);
    }

    public function test_every_provider_that_signs_in_can_ask_for_their_application(): void
    {
        foreach (Provider::all() as $provider) {
            $d = $this->applyAs($provider->user);

            $this->assertSame($this->applications($provider->id)->value('id'), $d['application_id'], "Provider {$provider->id}");
        }
    }

    public function test_signing_in_by_one_time_code_then_asking_works_end_to_end(): void
    {
        $user = User::where('email', 'provider@marine-booking.test')->firstOrFail();

        $this->postJson('/api/v1/auth/provider/otp/request', ['phone' => $user->phone])->assertSuccessful();
        $login = $this->postJson('/api/v1/auth/provider/otp/verify', ['phone' => $user->phone, 'code' => config('otp.default_code')])->assertSuccessful();

        $r = $this->hit($login->json('data.token'), 'GET', '/api/v1/provider/apply')->assertOk();

        $this->assertSame(['application_id', 'status', 'note', 'submitted_at', 'reviewed_at', 'can_resubmit', 'provider', 'stats'], array_keys($r->json('data')));
        $this->assertSame('approved', $r->json('data.status'));
    }

    // --- Only the gaps are filled -----------------------------------------------------------------------------------------

    public function test_running_it_again_adds_nothing_and_changes_nothing(): void
    {
        $before = DB::table('provider_requests')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->seed(ProviderApplicationsSeeder::class);
        $this->seed(ProviderApplicationsSeeder::class);

        $this->assertSame($before, DB::table('provider_requests')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_a_provider_without_one_gets_one_and_nobody_else_is_touched(): void
    {
        $provider = $this->providerWith('verified');
        $others = DB::table('provider_requests')->where('provider_id', '!=', $provider->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        DB::beginTransaction();
        try {
            $this->applications($provider->id)->delete();
            $this->seed(ProviderApplicationsSeeder::class);
            $made = $this->applications($provider->id)->get();
            $after = DB::table('provider_requests')->where('provider_id', '!=', $provider->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        } finally {
            DB::rollBack();
        }

        $this->assertCount(1, $made);
        $this->assertSame('approved', $made[0]->status);
        $this->assertSame($others, $after);
    }

    public function test_a_provider_that_has_applications_of_any_kind_is_left_exactly_as_it_is(): void
    {
        $provider = $this->providerWith('verified');

        DB::beginTransaction();
        try {
            // A history: an application rejected first, then another still waiting. The provider's own state says verified, which a naive seeder would "fix".
            $this->applications($provider->id)->update(['status' => 'rejected', 'rejection_reason' => 'First attempt', 'reviewed_at' => now()->subDays(9), 'submitted_at' => now()->subDays(10)]);
            DB::table('provider_requests')->insert(['uuid' => (string) Str::uuid(), 'user_id' => $provider->user_id, 'provider_id' => $provider->id, 'source' => 'registration', 'status' => 'pending',
                'submitted_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
            $before = $this->applications($provider->id)->get()->map(fn ($r) => (array) $r)->all();

            $this->seed(ProviderApplicationsSeeder::class);

            $this->assertSame($before, $this->applications($provider->id)->get()->map(fn ($r) => (array) $r)->all());
            $this->assertSame(['rejected', 'pending'], $this->applications($provider->id)->pluck('status')->all());
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_provider_that_is_deleted_gets_none(): void
    {
        $provider = $this->providerWith('verified');

        DB::beginTransaction();
        try {
            $this->applications($provider->id)->delete();
            $provider->delete();
            $this->seed(ProviderApplicationsSeeder::class);
            $count = $this->applications($provider->id)->count();
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, $count);
    }

    public function test_an_older_provider_is_submitted_when_it_was_created_and_reviewed_hours_later_not_backdated(): void
    {
        $provider = $this->providerWith('verified');
        $created = Carbon::parse($this->demoSeededAt())->subDays(40)->setTime(10, 0);

        DB::beginTransaction();
        try {
            DB::table('providers')->where('id', $provider->id)->update(['created_at' => $created]);
            $this->applications($provider->id)->delete();
            $this->seed(ProviderApplicationsSeeder::class);
            $application = $this->applications($provider->id)->first();
        } finally {
            DB::rollBack();
        }

        $this->assertSame($created->toDateTimeString(), $application->submitted_at, 'Submitted when the provider was created: not moved');
        $this->assertGreaterThan(Carbon::parse($application->submitted_at)->addHour()->toDateTimeString(), $application->reviewed_at);
        $this->assertLessThan(Carbon::parse($application->submitted_at)->addDays(2)->toDateTimeString(), $application->reviewed_at);
    }

    public function test_a_customer_who_became_a_provider_is_recorded_as_an_upgrade(): void
    {
        DB::beginTransaction();
        try {
            [$user] = $this->makeCustomer();
            $provider = Provider::create(['user_id' => $user->id, 'business_name' => 'Upgraded Co', 'slug' => 'upgraded-co']);
            $this->seed(ProviderApplicationsSeeder::class);
            $application = $this->applications($provider->id)->first();
        } finally {
            DB::rollBack();
        }

        $this->assertSame(['customer_upgrade', 'pending'], [$application->source, $application->status]);
    }

    public function test_it_runs_in_production_too_because_it_only_adds_what_a_providers_own_state_implies(): void
    {
        $provider = $this->providerWith('rejected');

        DB::beginTransaction();
        try {
            $this->applications($provider->id)->delete();
            $this->app->detectEnvironment(fn () => 'production');
            // In production the seed command asks to be confirmed, as it should: a deployment passes --force.
            $this->artisan('db:seed', ['--class' => ProviderApplicationsSeeder::class, '--force' => true])->assertExitCode(0);
            $made = $this->applications($provider->id)->first();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            DB::rollBack();
        }

        $this->assertSame('rejected', $made->status);
    }
}
