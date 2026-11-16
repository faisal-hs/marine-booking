<?php

namespace Tests\Feature\Admin;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * `php artisan storage:link` from the admin API. The links the app is configured with are pointed at a temporary folder, so these tests never touch the real
 * public/storage. Only the super admin may use it.
 */
class StorageLinkApiTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/storage-link';

    private string $tmp;

    private string $link;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->tmp = sys_get_temp_dir().'/storage-link-test-'.bin2hex(random_bytes(6));
        mkdir($this->tmp.'/public', 0777, true);
        mkdir($this->target = $this->tmp.'/app-public', 0777, true);
        $this->link = $this->tmp.'/public/storage';
        config(['filesystems.links' => [$this->link => $this->target]]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));

        parent::tearDown();
    }

    private function super(): string
    {
        return $this->tokenFor($this->makeAdmin('super_admin'), 'admin');
    }

    private function lookAt(string $token)
    {
        return $this->asAccount($token)->getJson(self::URL);
    }

    private function makeLink(string $token, array $body = [])
    {
        return $this->asAccount($token)->postJson(self::URL, $body);
    }

    // --- Who may ----------------------------------------------------------------------------------------------------

    public function test_only_the_super_admin_holds_the_permission(): void
    {
        $this->assertTrue(Role::findByName('super_admin')->hasPermissionTo('system.storage_link'));
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('system.storage_link'));
    }

    public function test_no_other_staff_role_a_provider_a_customer_or_nobody_may_look_or_run(): void
    {
        $tokens = [];
        foreach (['admin', 'content_admin', 'support_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $tokens[$role] = $this->tokenFor($this->makeAdmin($role), 'admin');
        }
        [$customer] = $this->makeCustomer();
        $tokens['customer'] = $this->tokenFor($customer, 'customer');
        $tokens['provider'] = $this->tokenFor($customer, 'provider');

        foreach ($tokens as $who => $token) {
            $this->lookAt($token)->assertForbidden();
            $this->makeLink($token)->assertForbidden();
            $this->assertFalse(is_link($this->link), "{$who} made the link");
        }

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL)->assertUnauthorized();
    }

    public function test_a_super_admin_whose_account_is_not_active_may_not(): void
    {
        $user = $this->makeAdmin('super_admin');
        $token = $this->tokenFor($user, 'admin');
        $user->forceFill(['status' => 'suspended'])->save();

        $this->makeLink($token)->assertForbidden();
        $this->assertFalse(is_link($this->link));
    }

    // --- Looking ---------------------------------------------------------------------------------------------------------

    public function test_the_status_says_missing_before_and_linked_after_and_changes_nothing(): void
    {
        $token = $this->super();

        $r = $this->lookAt($token)->assertOk();
        $this->assertSame(['linked' => false, 'links' => [['link' => 'storage', 'target' => 'app-public', 'state' => 'missing', 'target_exists' => true]]], $r->json('data'));
        $this->assertFalse(file_exists($this->link), 'Looking made nothing');

        $this->makeLink($token)->assertOk();
        $this->assertTrue($this->lookAt($token)->json('data.linked'));
    }

    public function test_it_reads_the_real_default_link_without_touching_it(): void
    {
        config(['filesystems.links' => null]);
        $before = is_link(public_path('storage')) ? readlink(public_path('storage')) : null;

        $r = $this->lookAt($this->super())->assertOk();

        $this->assertSame('public/storage', $r->json('data.links.0.link'));
        $this->assertSame('storage/app/public', $r->json('data.links.0.target'));
        $this->assertSame($before, is_link(public_path('storage')) ? readlink(public_path('storage')) : null);
    }

    public function test_the_answer_never_gives_away_where_on_the_server_the_app_is(): void
    {
        $token = $this->super();

        $body = $this->makeLink($token)->assertOk()->getContent().$this->lookAt($token)->getContent();

        $this->assertStringNotContainsString($this->tmp, $body);
        $this->assertStringNotContainsString(base_path(), $body);
    }

    // --- Making it ------------------------------------------------------------------------------------------------------

    public function test_it_makes_the_link_and_the_files_in_storage_are_reachable_through_it(): void
    {
        file_put_contents($this->target.'/picture.png', 'PNGDATA');

        $r = $this->makeLink($this->super())->assertOk();

        $this->assertSame(['Storage link created.', true, ['storage'], []], [$r->json('message'), $r->json('data.linked'), $r->json('data.created'), $r->json('data.replaced')]);
        $this->assertTrue(is_link($this->link));
        $this->assertSame(realpath($this->target), realpath($this->link));
        $this->assertSame('PNGDATA', file_get_contents($this->link.'/picture.png'));
        $this->assertNotEmpty($r->json('data.output'), 'What the command said is passed on');
    }

    public function test_doing_it_again_is_safe_and_changes_nothing(): void
    {
        $token = $this->super();
        $this->makeLink($token)->assertOk();
        $inode = lstat($this->link)['ino'];

        $again = $this->makeLink($token)->assertOk();

        $this->assertSame(['The storage link was already in place.', true, []], [$again->json('message'), $again->json('data.linked'), $again->json('data.created')]);
        $this->assertSame($inode, lstat($this->link)['ino'], 'The same link, not a new one');
    }

    public function test_every_configured_link_is_dealt_with(): void
    {
        $other = $this->tmp.'/second-target';
        mkdir($other);
        config(['filesystems.links' => [$this->link => $this->target, $this->tmp.'/public/second' => $other]]);
        symlink($this->target, $this->link);   // the first is already right

        $r = $this->makeLink($this->super())->assertOk();

        $this->assertSame(['second'], $r->json('data.created'));
        $this->assertSame(['linked', 'linked'], array_column($r->json('data.links'), 'state'));
    }

    // --- A link that points somewhere else ----------------------------------------------------------------------------------

    public function test_a_link_to_somewhere_else_is_reported_and_left_alone_unless_force_is_sent(): void
    {
        $elsewhere = $this->tmp.'/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $this->link);
        $token = $this->super();

        $this->assertSame('wrong_target', $this->lookAt($token)->json('data.links.0.state'));

        $refused = $this->makeLink($token)->assertStatus(409);
        $this->assertFalse($refused->json('success'));
        $this->assertStringContainsString('send force to replace it', $refused->json('message'));
        $this->assertSame(realpath($elsewhere), realpath($this->link), 'Left as it was');

        $replaced = $this->makeLink($token, ['force' => true])->assertOk();
        $this->assertSame(['storage'], $replaced->json('data.replaced'));
        $this->assertSame(realpath($this->target), realpath($this->link));
        $this->assertDirectoryExists($elsewhere, 'The old target is not touched');
    }

    public function test_force_may_be_sent_as_a_word_and_must_be_a_boolean(): void
    {
        $elsewhere = $this->tmp.'/elsewhere';
        mkdir($elsewhere);
        symlink($elsewhere, $this->link);
        $token = $this->super();

        $this->makeLink($token, ['force' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('force');
        $this->assertSame(realpath($elsewhere), realpath($this->link));

        $this->makeLink($token, ['force' => 'false'])->assertStatus(409);
        $this->makeLink($token, ['force' => 'true'])->assertOk();
        $this->assertSame(realpath($this->target), realpath($this->link));
    }

    // --- Something that is not a link ---------------------------------------------------------------------------------------

    public function test_a_real_folder_where_the_link_should_be_is_never_touched_even_with_force(): void
    {
        mkdir($this->link);
        file_put_contents($this->link.'/mine.txt', 'keep me');
        $token = $this->super();

        $this->assertSame('blocked', $this->lookAt($token)->json('data.links.0.state'));

        foreach ([[], ['force' => true]] as $body) {
            $r = $this->makeLink($token, $body)->assertStatus(409);
            $this->assertStringContainsString('real file or folder is in the way', $r->json('message'));
        }

        $this->assertFalse(is_link($this->link));
        $this->assertSame('keep me', file_get_contents($this->link.'/mine.txt'));
    }

    public function test_a_real_file_where_the_link_should_be_is_never_touched_either(): void
    {
        file_put_contents($this->link, 'a file');

        $this->makeLink($this->super(), ['force' => true])->assertStatus(409);

        $this->assertSame('a file', file_get_contents($this->link));
        $this->assertFalse(is_link($this->link));
    }

    // --- A target that is not there --------------------------------------------------------------------------------------

    public function test_a_link_whose_target_folder_does_not_exist_is_not_cured_by_force_and_says_so(): void
    {
        exec('rm -rf '.escapeshellarg($this->target));

        $r = $this->makeLink($this->super(), ['force' => true])->assertStatus(409);

        $this->assertStringContainsString('target folder (app-public) does not exist', $r->json('message'));
        $this->assertSame('broken', $r->json('data.links.0.state'));
        $this->assertFalse($r->json('data.links.0.target_exists'));
    }

    public function test_a_broken_link_is_repaired_by_the_command_itself_when_the_target_does_exist(): void
    {
        symlink($this->tmp.'/gone', $this->link);   // points at nothing
        $token = $this->super();

        $this->assertSame('broken', $this->lookAt($token)->json('data.links.0.state'));

        // A dangling link does not count as existing for the command, so it needs no force.
        $r = $this->makeLink($token)->assertOk();
        $this->assertSame(['storage'], $r->json('data.replaced'));
        $this->assertSame(realpath($this->target), realpath($this->link));
    }

    // --- A server that does not allow it ---------------------------------------------------------------------------------

    public function test_when_the_link_cannot_be_made_it_is_an_error_answer_not_a_crash(): void
    {
        config(['filesystems.links' => [$this->tmp.'/no-such-folder/storage' => $this->target]]);

        $r = $this->makeLink($this->super())->assertStatus(500);

        $this->assertFalse($r->json('success'));
        $this->assertStringContainsString('could not be created on this server', $r->json('message'));
        $this->assertSame('missing', $r->json('data.links.0.state'));
    }

    // --- Accountability ----------------------------------------------------------------------------------------------------

    public function test_every_run_is_logged_with_who_ran_it(): void
    {
        Log::spy();
        $user = $this->makeAdmin('super_admin');

        $this->makeLink($this->tokenFor($user, 'admin'))->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => str_contains($message, 'storage:link') && ($context['user_id'] ?? null) === $user->id && ($context['created'] ?? null) === ['storage'])->once();
    }

    public function test_making_it_is_limited_to_five_a_minute_and_looking_is_not(): void
    {
        $token = $this->super();

        foreach (range(1, 5) as $i) {
            $this->makeLink($token)->assertOk();
        }

        $this->makeLink($token)->assertStatus(429);
        $this->lookAt($token)->assertOk();
    }
}
