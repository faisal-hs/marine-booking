<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesSeededBulkDatabase;
use Tests\TestCase;

/**
 * A consistent database is no use if the application chokes on it. These tests drive the REAL API and admin screens
 * over the seeded data: they browse the public catalogue, sign in as bulk customers, providers and staff, open the
 * admin screens, and make a brand-new booking on top of thousands of seeded ones (which runs the capacity and pricing
 * logic against them).
 *
 * The data is seeded once for the class and only read here, except that the booking test adds one reservation.
 */
class BulkDataInTheAppTest extends TestCase
{
    use UsesSeededBulkDatabase;

    private function token(User|int $user, string $account): string
    {
        $user = $user instanceof User ? $user : User::findOrFail($user);

        return $user->createToken('bulk-test', [$account])->plainTextToken;
    }

    private function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function liveBulkUser(string $phonePrefix)
    {
        return DB::table('users')->where('phone', 'like', $phonePrefix.'%')->where('status', 'active')->whereNull('deleted_at');
    }

    private function staffWithRole(string $role): User
    {
        $id = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('roles.name', $role)->where('users.phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')->value('users.id');

        $this->assertNotNull($id, "No bulk staff member has the {$role} role");

        return User::findOrFail($id);
    }

    // --- The public catalogue ---------------------------------------------------------------------

    public function test_the_public_catalogue_lists_exactly_the_published_activities_and_pages_through_them(): void
    {
        $published = DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->count();

        $first = $this->getJson('/api/v1/public/activities?per_page=20&current_page=1')->assertOk();
        $this->assertCount(20, $first->json('data'));
        $this->assertSame($published, $first->json('pagination.total_items'));

        $second = $this->getJson('/api/v1/public/activities?per_page=20&current_page=2')->assertOk();
        $this->assertCount(20, $second->json('data'));
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'uuid'), array_column($second->json('data'), 'uuid')), 'Page 2 repeats page 1');

        // Nothing unpublished or deleted ever appears.
        $hidden = DB::table('activities')->where(fn ($q) => $q->where('status', '!=', 'published')->orWhereNotNull('deleted_at'))->pluck('uuid')->all();
        $this->assertEmpty(array_intersect($hidden, array_column($first->json('data'), 'uuid')));
    }

    public function test_the_catalogue_can_be_filtered_by_category_and_searched(): void
    {
        $category = DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->groupBy('category_id')->orderByRaw('count(*) desc')->value('category_id');
        $inCategory = DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->where('category_id', $category)->count();

        $this->getJson("/api/v1/public/activities?category_id={$category}&per_page=100")->assertOk()->assertJsonPath('pagination.total_items', $inCategory);

        $title = DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->value('title');
        $found = $this->getJson('/api/v1/public/activities?per_page=100&search='.urlencode($title))->assertOk();
        $this->assertContains($title, array_column($found->json('data'), 'title'));
    }

    public function test_a_published_activity_shows_everything_that_was_seeded_for_it(): void
    {
        $activity = DB::table('activities as a')->where('a.status', 'published')->whereNull('a.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('activity_feature_values as v')->whereColumn('v.activity_id', 'a.id'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('activity_options as o')->whereColumn('o.activity_id', 'a.id'))
            ->first(['a.id', 'a.slug', 'a.uuid', 'a.title']);
        $this->assertNotNull($activity, 'Expected a published activity that has features and add-ons');

        $detail = $this->getJson("/api/v1/public/activities/{$activity->slug}")->assertOk()->json('data');

        $this->assertSame($activity->uuid, $detail['uuid']);
        $this->assertNotEmpty($detail['booking_options']);
        $this->assertNotEmpty($detail['images']);
        $this->assertNotEmpty($detail['requirements']);
        $this->assertNotEmpty($detail['inclusions']);
        $this->assertNotEmpty($detail['features']);
        $this->assertNotEmpty($detail['options']);
        $this->assertCount(DB::table('activity_booking_options')->where('activity_id', $activity->id)->whereNull('deleted_at')->where('is_active', 1)->count(), $detail['booking_options']);
    }

    public function test_an_unpublished_or_deleted_activity_is_not_found(): void
    {
        foreach ([DB::table('activities')->where('status', 'draft')->whereNull('deleted_at')->value('slug'), DB::table('activities')->whereNotNull('deleted_at')->value('slug')] as $slug) {
            $this->assertNotNull($slug);
            $this->getJson("/api/v1/public/activities/{$slug}")->assertNotFound();
        }
    }

    // --- Customers -------------------------------------------------------------------------------------

    private function busiestCustomer(): object
    {
        $row = DB::table('reservations as r')->join('customers as c', 'c.id', '=', 'r.customer_id')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('u.phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->groupBy('c.id', 'u.id')->orderByRaw('count(*) desc')->first(['c.id as customer_id', 'u.id as user_id', DB::raw('count(*) as bookings')]);

        $this->assertNotNull($row);

        return $row;
    }

    public function test_a_customer_sees_their_own_seeded_bookings_with_prices_that_add_up(): void
    {
        $customer = $this->busiestCustomer();
        $token = $this->token($customer->user_id, 'customer');

        $list = $this->api($token)->getJson('/api/v1/customer/reservations?per_page=100')->assertOk();
        $this->assertSame((int) $customer->bookings, $list->json('pagination.total_items'));

        $uuid = $list->json('data.0.uuid');
        $one = $this->api($token)->getJson("/api/v1/customer/reservations/{$uuid}")->assertOk()->json('data');
        $this->assertEqualsWithDelta($one['total'], array_sum(array_column($one['pricing_breakdown'], 'total')), 0.005);

        // And nobody else's.
        $someoneElses = DB::table('reservations')->where('customer_id', '!=', $customer->customer_id)->value('uuid');
        $this->api($token)->getJson("/api/v1/customer/reservations/{$someoneElses}")->assertNotFound();
    }

    public function test_a_customers_favorites_and_notification_feed_work(): void
    {
        $customerId = DB::table('favorites')->groupBy('customer_id')->orderByRaw('count(*) desc')->value('customer_id');
        $userId = DB::table('customers')->where('id', $customerId)->value('user_id');
        $token = $this->token($userId, 'customer');

        $this->api($token)->getJson('/api/v1/customer/favorites?per_page=100')->assertOk()
            ->assertJsonPath('pagination.total_items', DB::table('favorites')->where('customer_id', $customerId)->count());

        $feedUser = DB::table('notifications as n')->join('customers as c', 'c.user_id', '=', 'n.notifiable_id')->groupBy('n.notifiable_id')->orderByRaw('count(*) desc')->value('n.notifiable_id');
        $feed = $this->api($this->token($feedUser, 'customer'))->getJson('/api/v1/customer/notifications?per_page=50')->assertOk();
        $this->assertNotEmpty($feed->json('data'));
        // The customer feed never carries a notice that only makes sense to a provider (the outcome of a provider request is
        // meant for both, so it is not in that list).
        $ids = array_column($feed->json('data'), 'id');
        $this->assertSame(0, DB::table('notifications')->whereIn('id', $ids)->whereIn('type', \App\Services\NotificationFeed::PROVIDER_ONLY)->count(), 'A provider-only notice reached a customer feed');
        $this->api($this->token($feedUser, 'customer'))->getJson('/api/v1/customer/notifications/unread-count')->assertOk();
    }

    public function test_a_new_booking_works_on_top_of_the_seeded_ones_and_is_priced_by_the_rules(): void
    {
        $customerUser = $this->liveBulkUser(BulkSeeder::CUSTOMER_PHONE_PREFIX)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('providers')->whereColumn('providers.user_id', 'users.id'))->value('id');
        $token = $this->token($customerUser, 'customer');
        $before = DB::table('reservations')->count();

        $from = Carbon::now()->addDay()->toDateString();
        $to = Carbon::now()->addDays(21)->toDateString();
        $candidates = DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('a.status', 'published')->whereNull('a.deleted_at')->where('u.status', 'active')->whereNull('u.deleted_at')->where('u.id', '!=', $customerUser)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('activity_booking_options as o')->whereColumn('o.activity_id', 'a.id')->where('o.booking_model', 'per_person')->where('o.is_active', 1)->whereNull('o.deleted_at'))
            ->orderBy('a.id')->limit(40)->get(['a.id', 'a.slug', 'a.uuid']);

        $booked = null;
        foreach ($candidates as $activity) {
            $availability = $this->getJson("/api/v1/public/activities/{$activity->slug}/availability?from_date={$from}&to_date={$to}")->json('data') ?? [];
            if ($availability === []) {
                continue;
            }

            $option = DB::table('activity_booking_options')->where('activity_id', $activity->id)->where('booking_model', 'per_person')->where('is_active', 1)->whereNull('deleted_at')->orderBy('id')->first();
            $adult = (float) DB::table('activity_pricing_rules')->where('activity_booking_option_id', $option->id)->where('participant_type', 'adult')->whereNull('schedule_period_id')->value('price');
            $child = DB::table('activity_pricing_rules')->where('activity_booking_option_id', $option->id)->where('participant_type', 'child')->value('price');

            $participants = ['adult' => 2] + ($child !== null ? ['child' => 1] : []);
            $date = array_key_first($availability);

            $response = $this->api($token)->postJson('/api/v1/customer/reservations', [
                'activity' => $activity->uuid, 'booking_option' => $option->uuid, 'reservation_date' => $date, 'participants' => $participants,
            ]);

            if ($response->status() === 201) {
                $booked = [$response, 2 * $adult + ($child !== null ? (float) $child : 0.0)];
                break;
            }
        }

        $this->assertNotNull($booked, 'Could not book any of the first 40 published activities that have availability: the seeded data and the booking logic disagree');
        [$response, $expectedTotal] = $booked;

        $response->assertJsonPath('data.status', 'pending');
        $this->assertEqualsWithDelta($expectedTotal, $response->json('data.total'), 0.005, "The total is not the pricing rules' prices");
        $this->assertSame($before + 1, DB::table('reservations')->count());

        // And it can be paid, which confirms it.
        $uuid = $response->json('data.uuid');
        $this->api($token)->postJson("/api/v1/customer/reservations/{$uuid}/payments", ['simulate' => 'sync_success'])->assertCreated();
        $this->api($token)->getJson("/api/v1/customer/reservations/{$uuid}")->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    // --- Providers and accounts ----------------------------------------------------------------------------

    public function test_an_approved_provider_manages_their_own_seeded_activities_and_bookings(): void
    {
        $provider = DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.verification_status', 'verified')->where('u.phone', 'like', BulkSeeder::PROVIDER_PHONE_PREFIX.'%')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('activities')->whereColumn('activities.provider_id', 'p.id')->whereNull('activities.deleted_at'))
            ->orderByRaw('(select count(*) from activities a where a.provider_id = p.id) desc')->first(['p.id', 'u.id as user_id']);
        $token = $this->token($provider->user_id, 'provider');

        $activities = $this->api($token)->getJson('/api/v1/provider/activities?per_page=100')->assertOk();
        $this->assertSame(DB::table('activities')->where('provider_id', $provider->id)->whereNull('deleted_at')->count(), $activities->json('pagination.total_items'));

        $bookings = $this->api($token)->getJson('/api/v1/provider/reservations?per_page=50')->assertOk();
        $this->assertSame(
            DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->where('a.provider_id', $provider->id)->count(),
            $bookings->json('pagination.total_items')
        );
    }

    public function test_a_provider_that_was_never_approved_is_shut_out_and_told_why(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $user = DB::table('providers as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.verification_status', $status)->where('u.phone', 'like', BulkSeeder::PROVIDER_PHONE_PREFIX.'%')
                ->where('u.status', 'active')->whereNull('u.deleted_at')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('provider_requests')->whereColumn('provider_requests.provider_id', 'p.id'))->value('u.id');
            $this->assertNotNull($user, "No {$status} provider with a request");
            $token = $this->token($user, 'provider');

            $this->api($token)->getJson('/api/v1/provider/activities')->assertForbidden()->assertJsonPath('data.provider_request_status', $status);
            $this->api($token)->getJson('/api/v1/account/provider-request')->assertOk()->assertJsonPath('data.status', $status);
        }
    }

    public function test_a_customer_who_became_a_provider_holds_both_accounts_and_can_switch(): void
    {
        $user = DB::table('users as u')->join('providers as p', 'p.user_id', '=', 'u.id')->where('p.verification_status', 'verified')->where('u.phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')
            ->where('u.status', 'active')->whereNull('u.deleted_at')->value('u.id');
        $this->assertNotNull($user, 'No customer became a provider in the data');
        $token = $this->token($user, 'customer');

        $this->api($token)->getJson('/api/v1/account')->assertOk()->assertJsonPath('data.account.available_accounts', ['customer', 'provider'])->assertJsonPath('data.account.provider_request_status', 'approved');

        $switched = $this->api($token)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertOk();
        $this->api($switched->json('data.token'))->getJson('/api/v1/provider/profile')->assertOk();
    }

    public function test_a_customer_with_a_waiting_request_sees_it(): void
    {
        $user = DB::table('users as u')->join('providers as p', 'p.user_id', '=', 'u.id')->join('provider_requests as q', 'q.provider_id', '=', 'p.id')
            ->where('q.status', 'pending')->where('u.phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->where('u.status', 'active')->whereNull('u.deleted_at')->value('u.id');
        $this->assertNotNull($user, 'No customer has a waiting request in the data');

        $this->api($this->token($user, 'customer'))->getJson('/api/v1/account/provider-request')->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.request.source', 'customer_upgrade');
    }

    // --- Staff and the admin screens ---------------------------------------------------------------------------

    public function test_a_staff_member_can_sign_in_to_the_admin_panel_with_the_demo_password(): void
    {
        $staff = $this->staffWithRole('super_admin');

        $this->post(route('admin.login'), ['email' => $staff->email, 'password' => 'password'])->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_every_admin_screen_opens_on_the_full_data_and_pages_through_it(): void
    {
        $admin = $this->actingAs($this->staffWithRole('super_admin'), 'web');

        foreach (['admin.dashboard', 'admin.customers.index', 'admin.providers.index', 'admin.activities.index', 'admin.reservations.index', 'admin.payments.index', 'admin.reviews.index',
            'admin.provider-requests.index', 'admin.notifications.log', 'admin.notifications.broadcasts.index', 'admin.reports.index', 'admin.users.index', 'admin.categories.index'] as $screen) {
            $admin->get(route($screen))->assertOk();
        }

        // Page 2 is a different set of rows from page 1.
        $one = $this->get(route('admin.customers.index'))->getContent();
        $two = $this->get(route('admin.customers.index', ['page' => 2]))->assertOk()->getContent();
        $this->assertNotSame($one, $two);
        preg_match_all('#admin/customers/([0-9a-f-]{36})#', $one, $a);
        preg_match_all('#admin/customers/([0-9a-f-]{36})#', $two, $b);
        $this->assertNotEmpty($a[1]);
        $this->assertEmpty(array_intersect(array_unique($a[1]), array_unique($b[1])));
    }

    public function test_the_admin_screens_find_things_in_the_data(): void
    {
        $this->actingAs($this->staffWithRole('super_admin'), 'web');

        $customer = DB::table('users')->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->where('status', 'active')->whereNull('deleted_at')->whereNotNull('email')->first(['name', 'phone', 'email']);
        $this->get(route('admin.customers.index', ['search' => $customer->phone]))->assertOk()->assertSee($customer->name);
        $this->get(route('admin.customers.index', ['search' => $customer->email]))->assertOk()->assertSee($customer->name);

        $activity = DB::table('activities')->where('status', 'published')->whereNull('deleted_at')->first(['title']);
        $this->get(route('admin.activities.index', ['search' => $activity->title]))->assertOk()->assertSee($activity->title);

        $pending = DB::table('provider_requests')->where('status', 'pending')->count();
        $this->get(route('admin.provider-requests.index'))->assertOk()->assertSee("Pending ({$pending})", false);
    }

    public function test_the_admin_inbox_links_to_the_reservations_its_notices_are_about(): void
    {
        // A staff member whose role may open reservations (a content admin may not, and would see no link).
        $staffId = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.notifiable_id')
            ->join('model_has_roles as m', 'm.model_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('u.phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')->whereIn('r.name', ['super_admin', 'admin', 'support_admin'])
            ->where('n.type', 'like', '%NewReservationAdminNotification')->value('n.notifiable_id');
        $this->assertNotNull($staffId, 'No staff member who may open reservations has an admin notice in the data');
        $staff = User::findOrFail($staffId);
        $this->assertTrue($staff->can('reservations.view'));

        $page = $this->actingAs($staff, 'web')->get(route('admin.notifications.index'))->assertOk();
        $this->assertMatchesRegularExpression('#admin/reservations/[0-9a-f-]{36}#', $page->getContent());
    }

    public function test_each_staff_role_sees_what_its_permissions_allow_on_the_full_data(): void
    {
        $support = $this->actingAs($this->staffWithRole('support_admin'), 'web');
        $support->get(route('admin.customers.index'))->assertOk();
        $support->get(route('admin.providers.index'))->assertOk();
        $support->get(route('admin.provider-requests.index'))->assertForbidden();
        $support->get(route('admin.notifications.log'))->assertOk();

        $content = $this->actingAs($this->staffWithRole('content_admin'), 'web');
        $content->get(route('admin.activities.index'))->assertOk();
        $content->get(route('admin.customers.index'))->assertForbidden();
        $content->get(route('admin.provider-requests.index'))->assertForbidden();

        $admin = $this->actingAs($this->staffWithRole('admin'), 'web');
        $admin->get(route('admin.provider-requests.index'))->assertOk();
    }
}
