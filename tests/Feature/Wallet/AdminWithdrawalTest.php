<?php

namespace Tests\Feature\Wallet;

use App\Models\Admin;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WithdrawalService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** The admin withdrawals screens: an admin pays by hand and records what happened. Who may see them, who may move money, and what each action does. */
class AdminWithdrawalTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private User $admin;

    private Withdrawal $pending;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider(['commission_rate' => 10, 'business_name' => 'Red Sea Divers']);
        $this->a['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($this->a, 1000);
        $this->admin = $this->makeAdmin();
        $this->pending = app(WithdrawalService::class)->request($this->a['provider']->refresh(), 100, 'wd-key-0001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function staffWith(string ...$permissions): User
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'job_title' => 'Clerk']);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function available(): float
    {
        return app(LedgerService::class)->balances($this->a['provider']->refresh())['available'];
    }

    // --- The list --------------------------------------------------------------------------------------------------------

    public function test_the_list_opens_on_the_waiting_requests_and_the_tabs_count_them_all(): void
    {
        $paid = app(WithdrawalService::class)->request($this->a['provider']->refresh(), 50, 'wd-key-0002');
        app(WithdrawalService::class)->markPaid($paid, $this->admin, 'TRF-1');

        $page = $this->as($this->admin)->get(route('admin.withdrawals.index'))->assertOk();

        $page->assertSee(__('admin.nav.withdrawals'))->assertSee('Red Sea Divers')->assertSee('100.00 SAR')->assertDontSee('50.00 SAR');
        $page->assertSee(__('admin.withdrawals.statuses.pending').' (1)', false)->assertSee(__('admin.withdrawals.statuses.paid').' (1)', false)->assertSee(__('admin.withdrawals.statuses.all').' (2)', false);

        $this->as($this->admin)->get(route('admin.withdrawals.index', ['status' => 'all']))->assertSee('100.00 SAR')->assertSee('50.00 SAR');
        $this->as($this->admin)->get(route('admin.withdrawals.index', ['status' => 'paid']))->assertSee('50.00 SAR')->assertDontSee('100.00 SAR');
    }

    public function test_the_list_can_be_searched_by_business_name(): void
    {
        $other = $this->approvedProvider(['commission_rate' => 0, 'business_name' => 'Blue Reef Centre']);
        $other['provider']->forceFill(['bank_account' => $this->bankAccount()])->save();
        $this->fundWallet($other, 300);
        app(WithdrawalService::class)->request($other['provider']->refresh(), 77, 'wd-key-0009');

        $this->as($this->admin)->get(route('admin.withdrawals.index', ['search' => 'Blue Reef']))->assertSee('77.00 SAR')->assertDontSee('100.00 SAR');
        $this->as($this->admin)->get(route('admin.withdrawals.index', ['search' => '%']))->assertDontSee('77.00 SAR')->assertDontSee('100.00 SAR');
    }

    // --- Who may do what ---------------------------------------------------------------------------------------------

    public function test_only_staff_with_the_permission_may_see_withdrawals(): void
    {
        $nobody = $this->staffWith();
        $this->as($nobody)->get(route('admin.withdrawals.index'))->assertForbidden();
        $this->as($nobody)->get(route('admin.withdrawals.show', $this->pending))->assertForbidden();

        $viewer = $this->staffWith('withdrawals.view');
        $this->as($viewer)->get(route('admin.withdrawals.index'))->assertOk();
        $this->as($viewer)->get(route('admin.withdrawals.show', $this->pending))->assertOk()->assertDontSee(__('admin.withdrawals.mark_paid'));
    }

    public function test_seeing_is_not_paying(): void
    {
        $viewer = $this->staffWith('withdrawals.view');

        $this->as($viewer)->post(route('admin.withdrawals.paid', $this->pending), ['payout_reference' => 'TRF-1'])->assertForbidden();
        $this->as($viewer)->post(route('admin.withdrawals.reject', $this->pending), ['reason' => 'No thanks'])->assertForbidden();
        $this->as($viewer)->post(route('admin.withdrawals.processing', $this->pending))->assertForbidden();

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_the_roles_that_run_the_platform_may_pay_out(): void
    {
        foreach (['super_admin', 'admin'] as $role) {
            $user = $this->makeAdmin($role);
            $this->assertTrue($user->can('withdrawals.view') && $user->can('withdrawals.process'), $role);
        }
    }

    // --- The page ---------------------------------------------------------------------------------------------------------

    public function test_the_page_has_what_is_needed_to_make_the_transfer(): void
    {
        $this->as($this->admin)->get(route('admin.withdrawals.show', $this->pending))->assertOk()
            ->assertSee('100.00 SAR')->assertSee('Red Sea Divers')->assertSee('Al Rajhi Bank')->assertSee('SA0380000000608010167519')
            ->assertSee(__('admin.withdrawals.mark_paid'))->assertSee(__('admin.withdrawals.reject'));
    }

    public function test_a_finished_withdrawal_offers_no_actions_and_shows_its_reference(): void
    {
        app(WithdrawalService::class)->markPaid($this->pending, $this->admin, 'TRF-2026-0042');

        $this->as($this->admin)->get(route('admin.withdrawals.show', $this->pending))->assertOk()
            ->assertSee('TRF-2026-0042')->assertDontSee(__('admin.withdrawals.mark_paid'))->assertDontSee(__('admin.withdrawals.reject'));
    }

    // --- The actions ---------------------------------------------------------------------------------------------------

    public function test_starting_the_transfer(): void
    {
        $this->as($this->admin)->post(route('admin.withdrawals.processing', $this->pending))->assertSessionHas('status', __('admin.withdrawals.done.started'));

        $this->assertSame('processing', $this->pending->refresh()->status);
        $this->assertStringContainsString('being processed', $this->a['user']->notifications()->latest()->first()->data['message']);
    }

    public function test_paying_needs_the_transfer_reference(): void
    {
        $this->as($this->admin)->post(route('admin.withdrawals.paid', $this->pending), [])->assertSessionHasErrors('payout_reference');
        $this->assertSame('pending', $this->pending->refresh()->status);

        $this->as($this->admin)->post(route('admin.withdrawals.paid', $this->pending), ['payout_reference' => 'TRF-9'])->assertSessionHas('status', __('admin.withdrawals.done.paid'));

        $this->pending->refresh();
        $this->assertSame(['paid', 'TRF-9', $this->admin->id], [$this->pending->status, $this->pending->payout_reference, $this->pending->processed_by_user_id]);
        $this->assertSame(800.0, $this->available(), '900 less the 100 that was paid out');
    }

    public function test_rejecting_needs_a_reason_gives_the_money_back_and_tells_the_provider_why(): void
    {
        $this->as($this->admin)->post(route('admin.withdrawals.reject', $this->pending), [])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $this->pending->refresh()->status);

        $this->as($this->admin)->post(route('admin.withdrawals.reject', $this->pending), ['reason' => 'The name on the account is different'])->assertSessionHas('status', __('admin.withdrawals.done.rejected'));

        $this->assertSame(['rejected', 900.0], [$this->pending->refresh()->status, $this->available()]);
        $this->assertStringContainsString('The name on the account is different', $this->a['user']->notifications()->latest()->first()->data['message']);
    }

    public function test_only_a_started_transfer_can_be_marked_as_failed(): void
    {
        $this->as($this->admin)->post(route('admin.withdrawals.fail', $this->pending), ['reason' => 'Bank returned it'])->assertSessionHas('error');
        $this->assertSame('pending', $this->pending->refresh()->status);
        $this->assertSame(800.0, $this->available(), 'Still reserved');

        $this->as($this->admin)->post(route('admin.withdrawals.processing', $this->pending));
        $this->as($this->admin)->post(route('admin.withdrawals.fail', $this->pending), ['reason' => 'Bank returned it'])->assertSessionHas('status', __('admin.withdrawals.done.failed'));

        $this->assertSame(['failed', 900.0], [$this->pending->refresh()->status, $this->available()]);
    }

    public function test_pressing_a_button_twice_does_nothing_the_second_time(): void
    {
        $this->as($this->admin)->post(route('admin.withdrawals.paid', $this->pending), ['payout_reference' => 'TRF-1'])->assertSessionHas('status');
        $this->as($this->admin)->post(route('admin.withdrawals.paid', $this->pending), ['payout_reference' => 'TRF-1'])->assertSessionHas('error');
        $this->as($this->admin)->post(route('admin.withdrawals.reject', $this->pending), ['reason' => 'Too late'])->assertSessionHas('error');

        $this->assertSame('paid', $this->pending->refresh()->status);
        $this->assertSame(0, WalletEntry::where('type', 'withdrawal_release')->count(), 'Nothing was given back');
        $this->assertSame(800.0, $this->available());
    }

    // --- Language -------------------------------------------------------------------------------------------------------

    public function test_every_text_exists_in_both_languages(): void
    {
        $flatten = function (array $a, string $prefix = '') use (&$flatten) {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $out += $flatten($v, "{$prefix}{$k}.") : $out["{$prefix}{$k}"] = $v;
            }

            return $out;
        };

        $en = $flatten(trans('admin.withdrawals', [], 'en'));
        $ar = $flatten(trans('admin.withdrawals', [], 'ar'));

        $this->assertGreaterThan(30, count($en));
        $this->assertSame([], array_diff(array_keys($en), array_keys($ar)), 'Missing in Arabic');
        $this->assertSame([], array_diff(array_keys($ar), array_keys($en)), 'Missing in English');
        $this->assertNotSame(trans('admin.nav.withdrawals', [], 'en'), trans('admin.nav.withdrawals', [], 'ar'));
    }
}
