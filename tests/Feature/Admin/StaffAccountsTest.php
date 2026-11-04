<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The Users screen manages STAFF only. Providers and customers have their own modules
 * because their accounts carry rules a generic form skipped (activities to take off the
 * public site, bookings that block a delete). The screen also used to let any admin
 * create or promote a super admin, and to flip a provider's verification status
 * directly, bypassing the verify flow.
 */
class StaffAccountsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Staff only --------------------------------------------------------------

    public function test_the_list_shows_staff_and_nobody_else(): void
    {
        $viewer = $this->makeAdmin('super_admin');
        $staff = $this->makeAdmin('support_admin');
        $staff->update(['name' => 'Visible Staffer']);
        [$providerUser] = $this->makeProvider('verified');
        $providerUser->update(['name' => 'Hidden Provider Owner']);
        User::factory()->customer()->create(['name' => 'Hidden Customer Person']);

        $page = $this->actingAs($viewer, 'web')->get(route('admin.users.index'));

        $page->assertOk()->assertSee('Visible Staffer')->assertDontSee('Hidden Provider Owner')->assertDontSee('Hidden Customer Person');
    }

    /** @return array<string, array{0: string}> */
    public static function nonStaffTypes(): array
    {
        return ['a provider' => ['provider'], 'a customer' => ['customer']];
    }

    #[DataProvider('nonStaffTypes')]
    public function test_a_provider_or_customer_is_not_found_on_the_staff_screen(string $type): void
    {
        $admin = $this->makeAdmin('super_admin');
        $target = $type === 'provider' ? $this->makeProvider('verified')[0] : User::factory()->customer()->create();

        $this->actingAs($admin, 'web')->get(route('admin.users.show', $target))->assertNotFound();
        $this->actingAs($admin, 'web')->get(route('admin.users.edit', $target))->assertNotFound();
        $this->actingAs($admin, 'web')->put(route('admin.users.update', $target), ['status' => 'suspended'])->assertNotFound();
        $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $target))->assertNotFound();

        $target->refresh();
        $this->assertSame('active', $target->status, 'The account must be untouched.');
        $this->assertNull($target->deleted_at);
    }

    public function test_the_create_form_cannot_make_a_provider_or_a_customer(): void
    {
        $admin = $this->makeAdmin('super_admin');

        $this->actingAs($admin, 'web')->post(route('admin.users.store'), [
            'user_type' => 'provider',
            'business_name' => 'Sneaky Diving Co',
            'name' => 'Sneaky Owner',
            'password' => 'a-strong-password',
            'role' => 'admin',
        ])->assertRedirect();

        $created = User::where('name', 'Sneaky Owner')->firstOrFail();
        $this->assertSame('admin', $created->user_type, 'Whatever user_type is sent, this screen only makes staff.');
        $this->assertDatabaseMissing('providers', ['business_name' => 'Sneaky Diving Co']);
    }

    public function test_the_role_list_offers_staff_roles_only(): void
    {
        $page = $this->actingAs($this->makeAdmin('super_admin'), 'web')->get(route('admin.users.create'));

        $page->assertOk();

        // Matched as a pattern, so a formatter that wraps the tag across lines cannot change the verdict.
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('#<option\s+value="support_admin"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option\s+value="provider"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option\s+value="customer"#', $html);
    }

    // --- Nobody can hand out more power than they have -------------------------------

    public function test_an_admin_cannot_create_a_super_admin(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'web')->post(route('admin.users.store'), [
            'name' => 'Would Be Boss',
            'email' => 'boss@marine-booking.test',
            'password' => 'a-strong-password',
            'role' => 'super_admin',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'boss@marine-booking.test']);
    }

    public function test_a_super_admin_can_create_a_super_admin(): void
    {
        $this->actingAs($this->makeAdmin('super_admin'), 'web')->post(route('admin.users.store'), [
            'name' => 'Second Boss',
            'email' => 'second.boss@marine-booking.test',
            'password' => 'a-strong-password',
            'role' => 'super_admin',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'second.boss@marine-booking.test')->firstOrFail()->hasRole('super_admin'));
    }

    public function test_an_admin_cannot_promote_themselves_to_super_admin(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'web')->put(route('admin.users.update', $admin), ['role' => 'super_admin'])->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertFalse($admin->fresh()->hasRole('super_admin'));
    }

    public function test_an_admin_cannot_edit_suspend_or_delete_a_super_admin(): void
    {
        $admin = $this->makeAdmin('admin');
        $boss = $this->makeAdmin('super_admin');

        $this->actingAs($admin, 'web')->get(route('admin.users.edit', $boss))->assertForbidden();
        $this->actingAs($admin, 'web')->put(route('admin.users.update', $boss), ['status' => 'suspended'])->assertForbidden();
        $this->actingAs($admin, 'web')->delete(route('admin.users.destroy', $boss))->assertForbidden();

        $boss->refresh();
        $this->assertSame('active', $boss->status);
        $this->assertNull($boss->deleted_at);
    }

    // --- The panel can never be locked out ----------------------------------------------

    public function test_the_last_super_admin_cannot_demote_themselves(): void
    {
        $only = $this->makeAdmin('super_admin');

        $this->actingAs($only, 'web')->put(route('admin.users.update', $only), ['role' => 'admin'])->assertSessionHasErrors('user');

        $this->assertTrue($only->fresh()->hasRole('super_admin'));
    }

    public function test_the_last_super_admin_cannot_suspend_themselves(): void
    {
        $only = $this->makeAdmin('super_admin');

        $this->actingAs($only, 'web')->put(route('admin.users.update', $only), ['status' => 'suspended'])->assertSessionHasErrors('user');

        $this->assertSame('active', $only->fresh()->status);
    }

    public function test_a_suspended_super_admin_does_not_count_as_a_fallback(): void
    {
        $actor = $this->makeAdmin('super_admin');
        $other = $this->makeAdmin('super_admin');
        $other->update(['status' => 'suspended']);

        $this->actingAs($actor, 'web')->put(route('admin.users.update', $actor), ['role' => 'admin'])->assertSessionHasErrors('user');

        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
    }

    public function test_a_super_admin_can_step_down_when_another_active_one_exists(): void
    {
        $actor = $this->makeAdmin('super_admin');
        $this->makeAdmin('super_admin');

        $this->actingAs($actor, 'web')->put(route('admin.users.update', $actor), ['role' => 'admin'])->assertSessionHasNoErrors();

        $this->assertTrue($actor->fresh()->hasRole('admin'));
    }

    public function test_a_super_admin_can_delete_another_super_admin_when_one_remains(): void
    {
        $actor = $this->makeAdmin('super_admin');
        $other = $this->makeAdmin('super_admin');

        $this->actingAs($actor, 'web')->delete(route('admin.users.destroy', $other))->assertRedirect(route('admin.users.index'));

        $this->assertSoftDeleted('users', ['id' => $other->id]);
    }
}
