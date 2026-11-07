<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** An admin sets a provider's commission as a percentage of each booking or as a fixed amount per booking, the same for every activity of that provider. */
class AdminCommissionTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $a;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->a = $this->approvedProvider(['commission_rate' => 10]);
        $this->admin = $this->makeAdmin();
    }

    private function update(array $terms)
    {
        $provider = $this->a['provider'];
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'web')->put(route('admin.providers.update', $provider), $terms + [
            'name' => $provider->user->name, 'phone' => $provider->user->phone, 'business_name' => $provider->business_name,
        ]);
    }

    public function test_a_new_provider_has_a_percentage_commission_by_default(): void
    {
        $this->assertSame('percentage', $this->a['provider']->refresh()->commission_type);
    }

    public function test_a_commission_can_be_a_percentage(): void
    {
        $this->update(['commission_type' => 'percentage', 'commission_rate' => 12.5])->assertSessionHasNoErrors();

        $provider = $this->a['provider']->refresh();
        $this->assertSame(['percentage', 12.5, 125.0], [$provider->commission_type, (float) $provider->commission_rate, $provider->commissionOn(1000)]);
    }

    public function test_a_commission_can_be_a_fixed_amount_larger_than_a_hundred(): void
    {
        $this->update(['commission_type' => 'fixed', 'commission_rate' => 150])->assertSessionHasNoErrors();

        $provider = $this->a['provider']->refresh();
        $this->assertSame(['fixed', 150.0, 150.0], [$provider->commission_type, (float) $provider->commission_rate, $provider->commissionOn(1000)]);
        $this->assertSame(150.0, $provider->commissionOn(400), 'The same on any booking');
    }

    public function test_a_percentage_cannot_be_more_than_a_hundred_but_the_same_number_is_a_fine_fixed_amount(): void
    {
        $this->update(['commission_type' => 'percentage', 'commission_rate' => 150])->assertSessionHasErrors('commission_rate');
        $this->assertSame(10.0, (float) $this->a['provider']->refresh()->commission_rate, 'Not changed');
    }

    public function test_an_unknown_type_or_a_negative_amount_is_refused(): void
    {
        $this->update(['commission_type' => 'tiered', 'commission_rate' => 5])->assertSessionHasErrors('commission_type');
        $this->update(['commission_type' => 'fixed', 'commission_rate' => -1])->assertSessionHasErrors('commission_rate');
    }

    public function test_leaving_them_empty_keeps_what_there_is(): void
    {
        $this->update(['commission_type' => 'fixed', 'commission_rate' => 40])->assertSessionHasNoErrors();
        $this->update([])->assertSessionHasNoErrors();

        $provider = $this->a['provider']->refresh();
        $this->assertSame(['fixed', 40.0], [$provider->commission_type, (float) $provider->commission_rate]);
    }

    public function test_the_admin_form_and_the_provider_page_show_the_terms(): void
    {
        $this->update(['commission_type' => 'fixed', 'commission_rate' => 40]);
        $this->flushHeaders();

        $this->actingAs($this->admin, 'web')->get(route('admin.providers.edit', $this->a['provider']))->assertOk()
            ->assertSee(__('admin.providers.commission_type'))->assertSee(__('admin.providers.commission_types.fixed'))->assertSee(__('admin.providers.commission_types.percentage'));
        $this->actingAs($this->admin, 'web')->get(route('admin.providers.show', $this->a['provider']))->assertOk()->assertSee('40.00 SAR');
    }
}
