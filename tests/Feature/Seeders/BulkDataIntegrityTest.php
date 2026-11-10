<?php

namespace Tests\Feature\Seeders;

use App\Services\NotificationFeed;
use Carbon\Carbon;
use Database\Seeders\Bulk\BulkSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesSeededBulkDatabase;
use Tests\TestCase;

/**
 * The Bulk data is only worth having if it is CONSISTENT: a booking must use a real departure and a price that
 * adds up, a day must not be overbooked, a payment must agree with its booking, a review must follow a completed
 * booking. Each test here states one such rule and fails with the offending rows.
 *
 * The data is seeded once for the whole class (see UsesSeededBulkDatabase); nothing here writes to it.
 * BULK_TEST_SCALE=1 runs the same rules over the full-size set.
 *
 * A rule that never meets a row to break would pass for no reason, so the first tests also insist that the rare cases
 * (a suspended provider, a refund, a hidden review ...) are really present in the data.
 */
class BulkDataIntegrityTest extends TestCase
{
    use UsesSeededBulkDatabase;

    private function scaled(int $atScaleOne): int
    {
        return max(1, (int) round($atScaleOne * $this->bulkScale()));
    }

    /** Fails with the first rows that break a rule, so the message says WHAT is wrong. */
    private function assertNone(\Illuminate\Support\Collection|array $offenders, string $rule): void
    {
        $offenders = collect($offenders);

        $this->assertTrue(
            $offenders->isEmpty(),
            "{$rule}. {$offenders->count()} row(s) break it, for example: ".$offenders->take(3)->map(fn ($r) => json_encode($r))->implode(' | ')
        );
    }

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    // --- Volume ----------------------------------------------------------------------------

    public function test_every_table_has_data_and_the_main_volumes_follow_the_scale(): void
    {
        foreach (['users', 'customers', 'providers', 'provider_requests', 'provider_profile_changes', 'wallet_entries', 'withdrawals', 'coupons', 'coupon_redemptions', 'review_replies', 'activity_views', 'cities', 'conversations', 'messages', 'support_tickets', 'provider_document_events', 'provider_documents', 'admins', 'categories', 'feature_definitions', 'activities', 'activity_images',
            'activity_feature_values', 'activity_requirements', 'activity_inclusions', 'activity_options', 'activity_schedule_periods', 'activity_availability_overrides',
            'activity_booking_options', 'activity_pricing_rules', 'reservations', 'reservation_capacity_locks', 'payments', 'reviews', 'favorites', 'devices', 'otps',
            'notifications', 'broadcasts'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} has no rows");
        }

        $this->assertSame($this->scaled(150000), DB::table('reservations')->count());
        $this->assertSame($this->scaled(15000), DB::table('activities')->count());
        $this->assertSame($this->scaled(60000), DB::table('otps')->count());
        $this->assertSame($this->scaled(600), DB::table('broadcasts')->count());
        $this->assertSame($this->scaled(25000) + $this->scaled(1500) + max(6, $this->scaled(40)), DB::table('users')->count());
    }

    public function test_the_database_passes_its_own_integrity_checks(): void
    {
        $this->assertSame([], DB::select('pragma foreign_key_check'), 'A row points at something that does not exist.');
        $this->assertSame('ok', DB::selectOne('pragma integrity_check')->integrity_check);
    }

    public function test_nothing_is_dated_in_the_future_that_has_already_happened(): void
    {
        $now = Carbon::now()->addMinutes(1)->toDateTimeString();

        foreach (['users', 'customers', 'providers', 'provider_requests', 'provider_profile_changes', 'wallet_entries', 'withdrawals', 'coupons', 'coupon_redemptions', 'review_replies', 'activity_views', 'cities', 'conversations', 'messages', 'support_tickets', 'activities', 'reservations', 'payments', 'reviews', 'notifications', 'otps', 'broadcasts', 'devices'] as $table) {
            $this->assertSame(0, DB::table($table)->where('created_at', '>', $now)->count(), "{$table} has rows created in the future");
        }
        $this->assertSame(0, DB::table('provider_requests')->whereNotNull('reviewed_at')->where('reviewed_at', '>', $now)->count());
        $this->assertSame(0, DB::table('activities')->whereNotNull('published_at')->where('published_at', '>', $now)->count());
    }

    // --- Accounts --------------------------------------------------------------------------------

    public function test_accounts_line_up_with_the_users_behind_them(): void
    {
        $customers = DB::table('users')->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%');
        $this->assertSame($this->scaled(25000), $customers->count());
        $this->assertSame(0, DB::table('users')->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->where('user_type', '!=', 'customer')->count());
        $this->assertSame(0, DB::table('users')->where('phone', 'like', BulkSeeder::PROVIDER_PHONE_PREFIX.'%')->where('user_type', '!=', 'provider')->count());
        $this->assertSame(0, DB::table('users')->where('phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')->where('user_type', '!=', 'admin')->count());

        // Every customer user has a customer account.
        $this->assertNone(
            DB::table('users')->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('customers')->whereColumn('customers.user_id', 'users.id'))->limit(3)->pluck('phone'),
            'Every customer user has a customer account'
        );
        // Staff have no password-less account and no customer or provider account.
        $this->assertSame(0, DB::table('users')->where('user_type', 'admin')->whereNull('password')->where('phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')->count());
        $this->assertSame(0, DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->where('users.user_type', 'admin')->count());
        $this->assertSame(0, DB::table('providers')->join('users', 'users.id', '=', 'providers.user_id')->where('users.user_type', 'admin')->count());
    }

    public function test_the_staff_have_the_roles_they_should(): void
    {
        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')->where('users.phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')
            ->groupBy('roles.name')->selectRaw('roles.name as role, count(*) as total')->pluck('total', 'role');

        $this->assertGreaterThanOrEqual(1, $roles['super_admin'] ?? 0, 'There must always be a super admin');
        foreach (['admin', 'support_admin', 'content_admin'] as $role) {
            $this->assertGreaterThanOrEqual(1, $roles[$role] ?? 0, "No staff member has the {$role} role");
        }
        $this->assertSame(max(6, $this->scaled(40)), (int) $roles->sum());
        $this->assertSame(DB::table('admins')->count(), DB::table('users')->where('phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')->count());
    }

    public function test_a_chosen_default_account_is_one_the_person_can_actually_use(): void
    {
        $this->assertNone(
            DB::table('users')->where('default_account', 'provider')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('providers')->whereColumn('providers.user_id', 'users.id')->where('providers.verification_status', 'verified'))->limit(3)->pluck('phone'),
            'Only an approved provider can choose the provider side as their default'
        );
    }

    // --- Providers, requests and documents --------------------------------------------------------------

    public function test_provider_requests_and_provider_status_tell_the_same_story(): void
    {
        // An approved provider has an approved request; a rejected one has a rejected request; both were decided by staff.
        $this->assertNone(
            DB::table('providers')->where('verification_status', 'verified')->where('providers.slug', 'not like', 'demo%')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('provider_requests')->whereColumn('provider_requests.provider_id', 'providers.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('provider_requests')->whereColumn('provider_requests.provider_id', 'providers.id')->where('status', 'approved'))
                ->limit(3)->pluck('slug'),
            'A verified provider with requests must have an approved one'
        );
        $this->assertNone(
            DB::table('providers')->where('verification_status', 'rejected')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('provider_requests')->whereColumn('provider_requests.provider_id', 'providers.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('provider_requests')->whereColumn('provider_requests.provider_id', 'providers.id')->where('status', 'rejected'))
                ->limit(3)->pluck('slug'),
            'A rejected provider with requests must have a rejected one'
        );
        $this->assertNone(
            DB::table('provider_requests')->where('status', 'pending')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('providers')->whereColumn('providers.id', 'provider_requests.provider_id')->where('verification_status', '!=', 'pending'))->limit(3)->pluck('id'),
            'A pending request belongs to a provider that is still pending'
        );
        // At most one waiting request per provider.
        $this->assertNone(DB::table('provider_requests')->where('status', 'pending')->groupBy('provider_id')->havingRaw('count(*) > 1')->pluck('provider_id'), 'One waiting request per provider');
        // Decided requests carry who decided and when; a rejection carries its reason; a waiting one carries neither.
        $this->assertNone(DB::table('provider_requests')->whereIn('status', ['approved', 'rejected'])->whereNull('reviewed_at')->limit(3)->pluck('id'), 'A decided request has a decision time');
        $this->assertNone(DB::table('provider_requests')->where('status', 'rejected')->whereNull('rejection_reason')->limit(3)->pluck('id'), 'A rejection has a reason');
        $this->assertNone(DB::table('provider_requests')->where('status', 'pending')->where(fn ($q) => $q->whereNotNull('reviewed_at')->orWhereNotNull('reviewed_by'))->limit(3)->pluck('id'), 'A waiting request is undecided');
        // The source matches the account the person registered as.
        $this->assertNone(
            DB::table('provider_requests')->join('users', 'users.id', '=', 'provider_requests.user_id')
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('source', 'registration')->where('users.user_type', '!=', 'provider'))->orWhere(fn ($w) => $w->where('source', 'customer_upgrade')->where('users.user_type', '!=', 'customer')))
                ->limit(3)->pluck('provider_requests.id'),
            'A request made by registering comes from a provider, and an upgrade from a customer'
        );
    }

    public function test_every_provider_with_a_request_has_the_three_required_documents_and_the_files_exist(): void
    {
        $required = app(\App\Services\VerificationDocumentTypes::class)->mandatoryKeys();

        $providers = DB::table('provider_requests')->distinct()->pluck('provider_id');
        $this->assertGreaterThan(0, $providers->count());

        $byProvider = DB::table('provider_documents')->whereIn('type', $required)->get(['provider_id', 'type'])->groupBy('provider_id')->map(fn ($rows) => $rows->pluck('type')->unique()->sort()->values()->all());
        $missing = $providers->filter(fn ($id) => ($byProvider[$id] ?? []) !== collect($required)->sort()->values()->all())->values();
        $this->assertNone($missing->take(3)->map(fn ($id) => ['provider_id' => $id]), 'Every provider with a request has all three required documents');

        // Documents are real files (they are offered for download), and live only under the private disk.
        $disk = Storage::disk(config('providers.documents.disk'));
        foreach (DB::table('provider_documents')->limit(300)->get(['path']) as $document) {
            $this->assertTrue($disk->exists($document->path), "File missing for {$document->path}");
        }
        $this->assertSame(0, DB::table('provider_documents')->whereNotIn('type', array_keys(app(\App\Services\VerificationDocumentTypes::class)->titles()))->count(), 'Unknown document type');
    }

    public function test_document_statuses_agree_with_the_providers_verification(): void
    {
        $byStatus = fn (string $verification) => DB::table('provider_documents')->join('providers', 'providers.id', '=', 'provider_documents.provider_id')
            ->where('providers.verification_status', $verification)->whereNull('provider_documents.superseded_at')->selectRaw('provider_documents.status, COUNT(*) AS total')->groupBy('provider_documents.status')->pluck('total', 'status')->all();

        $this->assertSame(['approved'], array_keys($byStatus('verified')), 'An admin verified these providers on their documents');
        $this->assertSame(['pending'], array_keys($byStatus('pending')), 'A waiting provider\'s documents are waiting too');
        $this->assertSame(['rejected'], array_keys($byStatus('rejected')));
    }

    public function test_every_decided_document_says_who_decided_and_a_rejection_says_why(): void
    {
        $this->assertSame(0, DB::table('provider_documents')->where('status', 'pending')->whereNotNull('reviewed_at')->count(), 'A waiting document has not been decided');
        $this->assertSame(0, DB::table('provider_documents')->whereIn('status', ['approved', 'rejected'])->whereNull('reviewed_at')->count());
        $this->assertSame(0, DB::table('provider_documents')->where('status', 'rejected')->whereNull('review_note')->count(), 'A rejection has a reason');
        $this->assertSame(0, DB::table('provider_documents')->whereColumn('reviewed_at', '<', 'created_at')->count(), 'Nothing is decided before it was uploaded');
        $this->assertSame(0, DB::table('provider_documents')->whereNotIn('status', ['pending', 'approved', 'rejected'])->count());
        $this->assertSame(0, DB::table('provider_documents')->where('status', '!=', 'approved')->whereNotNull('expires_at')->count(), 'Only an approved document has a valid-until date');
        $this->assertGreaterThan(0, DB::table('provider_documents')->where('status', 'approved')->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString())->count(), 'Some approved documents have already expired');
    }

    public function test_at_most_one_version_of_each_document_is_in_force_and_replaced_ones_were_rejected(): void
    {
        $duplicates = DB::table('provider_documents')->whereNull('superseded_at')->selectRaw('provider_id, type, COUNT(*) AS total')->groupBy('provider_id', 'type')->having('total', '>', 1)->count();
        $this->assertSame(0, $duplicates, 'One version in force per provider and type');

        $this->assertGreaterThan(0, DB::table('provider_documents')->whereNotNull('superseded_at')->count(), 'Some documents were replaced');
        $this->assertSame(0, DB::table('provider_documents')->whereNotNull('superseded_at')->where('status', '!=', 'rejected')->count(), 'What was replaced had been rejected');
    }

    public function test_the_review_history_agrees_with_the_documents(): void
    {
        $this->assertSame(0, DB::table('provider_documents')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('provider_document_events as e')
            ->whereColumn('e.provider_document_id', 'provider_documents.id')->where('e.action', 'submitted'))->count(), 'Every document was uploaded, and the history says so');

        foreach (['approved', 'rejected'] as $decision) {
            $this->assertSame(0, DB::table('provider_documents')->where('status', $decision)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('provider_document_events as e')
                ->whereColumn('e.provider_document_id', 'provider_documents.id')->where('e.action', $decision))->count(), "Every {$decision} document has that decision in its history");
        }

        $this->assertSame(0, DB::table('provider_document_events')->where('created_at', '>', now()->toDateTimeString())->count(), 'No history in the future');
        $this->assertGreaterThan(0, DB::table('provider_document_events')->where('action', 'update_requested')->count());
        $this->assertSame(0, DB::table('provider_document_events as e')->join('provider_documents as d', 'd.id', '=', 'e.provider_document_id')->where('e.action', 'update_requested')
            ->where(fn ($q) => $q->where('d.status', '!=', 'approved')->orWhereNotNull('d.superseded_at'))->count(), 'Only an approved version in force is asked to be updated');
    }

    public function test_the_extra_document_types_are_optional_and_the_platform_ones_are_untouched(): void
    {
        $this->assertSame(['commercial_registration', 'insurance_certificate', 'safety_certification'], collect(app(\App\Services\VerificationDocumentTypes::class)->mandatoryKeys())->sort()->values()->all(), 'Nothing mandatory was added');
        $this->assertGreaterThanOrEqual(11, DB::table('verification_document_types')->count());
        $this->assertSame(1, DB::table('verification_document_types')->where('is_active', false)->count(), 'One of the extras is switched off');
    }

    public function test_coupons_are_valid_and_belong_to_providers_that_were_verified(): void
    {
        $this->assertGreaterThan(0, DB::table('coupons')->count());
        $this->assertSame(0, DB::table('coupons')->join('providers', 'providers.id', '=', 'coupons.provider_id')->where('providers.verification_status', '!=', 'verified')->count());
        $this->assertSame(0, DB::table('coupons')->whereNotIn('type', ['percent', 'fixed'])->count());
        $this->assertSame(0, DB::table('coupons')->where('value', '<=', 0)->count());
        $this->assertSame(0, DB::table('coupons')->where('type', 'percent')->where('value', '>', 100)->count());
        $this->assertSame(0, DB::table('coupons')->where('type', 'fixed')->whereNotNull('max_discount')->count(), 'A cap only means something for a percentage');
        $this->assertSame(0, DB::table('coupons')->whereRaw('code != UPPER(code)')->count(), 'Codes are in capitals');
        $this->assertSame(0, DB::table('coupons')->selectRaw('provider_id, code, COUNT(*) AS n')->groupBy('provider_id', 'code')->having('n', '>', 1)->get()->count(), 'A code is unique among the provider\'s own');
        $this->assertGreaterThan(0, DB::table('coupons')->where('expires_at', '<', now()->toDateTimeString())->count(), 'Some have expired');
        $this->assertGreaterThan(0, DB::table('coupons')->where('is_active', 0)->count(), 'Some are switched off');
    }

    public function test_a_coupon_is_limited_only_to_activities_of_its_own_provider(): void
    {
        $this->assertGreaterThan(0, DB::table('activity_coupon')->count());
        $this->assertSame(0, DB::table('activity_coupon as ac')->join('coupons as c', 'c.id', '=', 'ac.coupon_id')->join('activities as a', 'a.id', '=', 'ac.activity_id')->whereColumn('a.provider_id', '!=', 'c.provider_id')->count());
    }

    public function test_every_redemption_is_valid_for_its_booking(): void
    {
        $this->assertGreaterThan(0, DB::table('coupon_redemptions')->count());
        $rows = DB::table('coupon_redemptions as r')->join('coupons as c', 'c.id', '=', 'r.coupon_id')->join('reservations as b', 'b.id', '=', 'r.reservation_id')->join('activities as a', 'a.id', '=', 'b.activity_id')
            ->get(['r.id', 'r.code', 'r.discount_amount', 'r.customer_id', 'c.code as coupon_code', 'c.provider_id as coupon_provider', 'c.created_at as coupon_made', 'c.expires_at', 'c.min_order_amount', 'c.id as coupon_id',
                'b.subtotal', 'b.discount', 'b.total', 'b.tax', 'b.customer_id as booking_customer', 'b.created_at as booked', 'a.provider_id as activity_provider', 'b.activity_id']);
        $restricted = DB::table('activity_coupon')->get()->groupBy('coupon_id')->map(fn ($r) => $r->pluck('activity_id')->all());

        $wrong = [];
        foreach ($rows as $r) {
            $problems = [];
            $r->coupon_provider === $r->activity_provider || $problems[] = 'a coupon of another provider';
            $r->code === $r->coupon_code || $problems[] = 'the code differs';
            abs((float) $r->discount_amount - (float) $r->discount) < 0.005 || $problems[] = 'the redemption and the booking disagree on the discount';
            (float) $r->discount > 0 || $problems[] = 'no discount';
            (float) $r->total >= (float) config('coupons.minimum_total') - 0.005 || $problems[] = 'a booking cheaper than the minimum';
            $r->customer_id === $r->booking_customer || $problems[] = 'another customer';
            $r->booked >= $r->coupon_made || $problems[] = 'used before the coupon existed';
            ($r->expires_at === null || $r->booked <= $r->expires_at) || $problems[] = 'used after it expired';
            ($r->min_order_amount === null || (float) $r->subtotal >= (float) $r->min_order_amount) || $problems[] = 'smaller than its smallest booking';
            (! isset($restricted[$r->coupon_id]) || in_array($r->activity_id, $restricted[$r->coupon_id], true)) || $problems[] = 'an activity the coupon does not cover';
            $problems === [] || $wrong[] = "redemption {$r->id}: ".implode(', ', $problems);
        }

        $this->assertSame([], array_slice($wrong, 0, 10), count($wrong).' redemptions are not valid for their booking');
    }

    public function test_no_coupon_was_used_more_often_than_its_limit_among_the_bookings_still_alive(): void
    {
        $over = DB::table('coupon_redemptions as r')->join('coupons as c', 'c.id', '=', 'r.coupon_id')->join('reservations as b', 'b.id', '=', 'r.reservation_id')
            ->whereNotIn('b.status', ['cancelled', 'expired'])->whereNotNull('c.usage_limit')->groupBy('c.id', 'c.usage_limit')->havingRaw('COUNT(*) > c.usage_limit')->select('c.id')->get();

        $this->assertSame(0, $over->count());
    }

    public function test_a_booking_without_a_coupon_has_no_discount_and_one_with_a_coupon_has_exactly_one(): void
    {
        $this->assertSame(0, DB::table('reservations')->where('discount', '>', 0)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('coupon_redemptions as r')->whereColumn('r.reservation_id', 'reservations.id'))->count());
        $this->assertSame(0, DB::table('coupon_redemptions')->selectRaw('reservation_id, COUNT(*) AS n')->groupBy('reservation_id')->having('n', '>', 1)->get()->count());
    }

    public function test_every_booking_says_how_the_customer_arrived(): void
    {
        $this->assertSame(0, DB::table('reservations')->whereNull('source')->orWhereNotIn('source', ['direct', 'category', 'recommendations', 'social'])->count());
        $this->assertSame(4, DB::table('reservations')->distinct()->count('source'), 'All four sources occur');
    }

    public function test_replies_are_by_the_activitys_provider_after_the_review_and_never_to_a_hidden_one(): void
    {
        $this->assertGreaterThan(0, DB::table('review_replies')->count());
        $join = fn () => DB::table('review_replies as p')->join('reviews as r', 'r.id', '=', 'p.review_id')->join('activities as a', 'a.id', '=', 'r.activity_id');

        $this->assertSame(0, $join()->whereColumn('a.provider_id', '!=', 'p.provider_id')->count(), 'Only the activity\'s own provider answers');
        $this->assertSame(0, $join()->whereColumn('p.created_at', '<', 'r.created_at')->count(), 'Nothing is answered before it was written');
        $this->assertSame(0, $join()->where('r.is_hidden', 1)->count(), 'A hidden review is never answered');
        $this->assertSame(0, DB::table('review_replies')->where('is_active', 1)->selectRaw('review_id, COUNT(*) AS n')->groupBy('review_id')->having('n', '>', 1)->get()->count(), 'One reply stands per review');
        $this->assertSame(0, DB::table('review_replies')->where('is_active', 1)->whereNotNull('deleted_at')->count());
        $this->assertSame(0, DB::table('review_replies')->whereNull('is_active')->whereNull('deleted_at')->count(), 'A deleted reply says when');
        $this->assertSame(0, DB::table('review_replies')->whereNull('is_active')->whereNull('deleted_by_user_id')->count(), 'and by whom');
        $this->assertGreaterThan(0, DB::table('review_replies')->whereNull('is_active')->count(), 'Some replies were deleted');
        $this->assertSame(0, DB::table('review_replies')->where('created_at', '>', now()->toDateTimeString())->count(), 'No reply from the future');
    }

    public function test_page_views_are_of_published_activities_one_per_visitor_per_day_and_never_in_the_future(): void
    {
        $this->assertGreaterThan(0, DB::table('activity_views')->count());
        $this->assertSame(0, DB::table('activity_views as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->where('a.status', '!=', 'published')->count());
        $this->assertSame(0, DB::table('activity_views')->where('viewed_on', '>', now()->toDateString())->count());
        $this->assertSame(0, DB::table('activity_views')->where('viewed_on', '<', now()->subDays(60)->toDateString())->count(), 'Within the last sixty days');
        $this->assertSame(0, DB::table('activity_views')->whereNotIn('source', ['direct', 'category', 'recommendations', 'social'])->count());
        $this->assertSame(0, DB::table('activity_views')->selectRaw('activity_id, visitor_hash, viewed_on, COUNT(*) AS n')->groupBy('activity_id', 'visitor_hash', 'viewed_on')->having('n', '>', 1)->get()->count());
        $this->assertSame(0, DB::table('activity_views')->whereRaw('LENGTH(visitor_hash) != 40')->count());
    }

    public function test_cities_are_unique_and_every_activity_is_in_its_providers_city_and_only_published_ones_are_featured(): void
    {
        $this->assertGreaterThan(0, DB::table('cities')->count());
        $this->assertSame(0, DB::table('cities')->selectRaw('slug, COUNT(*) AS n')->groupBy('slug')->having('n', '>', 1)->get()->count());

        $placed = DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')->join('cities as c', 'c.id', '=', 'a.city_id')->whereRaw('LOWER(TRIM(c.name_en)) != LOWER(TRIM(p.city))')->count();
        $this->assertSame(0, $placed, 'An activity is in its provider\'s city');
        $this->assertGreaterThan(0, DB::table('activities')->whereNotNull('city_id')->count());
        $this->assertSame(0, DB::table('activities')->where('is_featured', 1)->where('status', '!=', 'published')->count());
        $this->assertGreaterThan(0, DB::table('activities')->where('is_featured', 1)->count());
        $this->assertSame(0, DB::table('activities')->where('is_featured', 1)->whereNotNull('suspended_at')->count());
    }

    public function test_conversations_are_between_a_customer_and_the_provider_of_an_activity_they_booked_and_unique(): void
    {
        $this->assertGreaterThan(0, DB::table('conversations')->count());
        $this->assertSame(0, DB::table('conversations as c')->join('activities as a', 'a.id', '=', 'c.activity_id')->whereColumn('a.provider_id', '!=', 'c.provider_id')->count(), 'The activity is the provider\'s');
        $this->assertSame(0, DB::table('conversations as c')->join('providers as p', 'p.id', '=', 'c.provider_id')->where('p.verification_status', '!=', 'verified')->count());
        $this->assertSame(0, DB::table('conversations')->selectRaw('customer_id, provider_id, activity_id, COUNT(*) AS n')->groupBy('customer_id', 'provider_id', 'activity_id')->having('n', '>', 1)->get()->count());
        $this->assertSame(0, DB::table('conversations as c')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reservations as r')->whereColumn('r.customer_id', 'c.customer_id')->whereColumn('r.activity_id', 'c.activity_id'))->count(), 'Only people who booked');
    }

    public function test_messages_alternate_run_forward_in_time_never_in_the_future_and_the_unread_ones_are_the_recent_ones(): void
    {
        $this->assertGreaterThan(0, DB::table('messages')->count());
        $this->assertSame(0, DB::table('messages')->whereNotIn('sender_type', ['customer', 'provider'])->count());
        $this->assertSame(0, DB::table('messages')->where('created_at', '>', now()->toDateTimeString())->count());
        $this->assertSame(0, DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')->whereColumn('m.created_at', '<', 'c.created_at')->count(), 'Nothing before the conversation began');
        $this->assertSame(0, DB::table('messages')->whereNotNull('read_at')->whereColumn('read_at', '<', 'created_at')->count());
        $this->assertSame(0, DB::table('messages')->whereNull('read_at')->where('created_at', '<', now()->subDay()->toDateTimeString())->count(), 'Anything over a day old has been read');
        $this->assertGreaterThan(0, DB::table('messages')->whereNull('read_at')->where('sender_type', 'provider')->count(), 'Some provider messages are unread');
        $first = DB::table('messages')->selectRaw('conversation_id, MIN(id) AS first_id')->groupBy('conversation_id')->pluck('first_id');
        $this->assertSame(0, DB::table('messages')->whereIn('id', $first)->where('sender_type', '!=', 'customer')->count(), 'The customer writes first');
        $this->assertSame(0, DB::table('conversations as c')->whereRaw('c.last_message_at IS NOT (SELECT MAX(created_at) FROM messages WHERE conversation_id = c.id)')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('messages')->whereColumn('conversation_id', 'c.id'))->count(), 'The last-message time is the newest message');
    }

    public function test_support_tickets_are_the_customers_own_on_a_known_topic_and_after_they_joined(): void
    {
        $this->assertGreaterThan(0, DB::table('support_tickets')->count());
        $this->assertSame(0, DB::table('support_tickets')->whereNotIn('topic', config('customer_app.support_topics'))->count());
        $this->assertSame(0, DB::table('support_tickets')->whereNotIn('status', ['open', 'answered', 'closed'])->count());
        $this->assertSame(0, DB::table('support_tickets as t')->join('reservations as r', 'r.id', '=', 't.reservation_id')->whereColumn('r.customer_id', '!=', 't.customer_id')->count(), 'A booking is the ticket writer\'s own');
        $this->assertSame(0, DB::table('support_tickets as t')->join('customers as c', 'c.id', '=', 't.customer_id')->whereColumn('t.created_at', '<', 'c.created_at')->count());
        $this->assertSame(0, DB::table('support_tickets')->where('created_at', '>', now()->toDateTimeString())->count());
        $this->assertSame(3, DB::table('support_tickets')->distinct()->count('status'), 'Open, answered and closed all occur');
    }

    // --- Activities -----------------------------------------------------------------------------------

    public function test_activities_obey_the_provider_rules(): void
    {
        // An unapproved provider only has drafts; a suspended or deleted provider's activities are all inactive.
        $this->assertNone(
            DB::table('activities')->join('providers', 'providers.id', '=', 'activities.provider_id')->join('users', 'users.id', '=', 'providers.user_id')
                ->where('activities.slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->where('providers.verification_status', '!=', 'verified')
                ->whereNull('activities.deleted_at')->where('activities.status', '!=', 'draft')->limit(3)->pluck('activities.slug'),
            'An unapproved provider only has draft activities'
        );
        $this->assertNone(
            DB::table('activities')->join('providers', 'providers.id', '=', 'activities.provider_id')->join('users', 'users.id', '=', 'providers.user_id')
                ->where('activities.slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->where(fn ($q) => $q->where('users.status', '!=', 'active')->orWhereNotNull('users.deleted_at'))
                ->where('activities.status', 'published')->limit(3)->pluck('activities.slug'),
            "Nothing a suspended or deleted provider owns is published"
        );
        // Published means it has a publication date; rejected means it has a reason; slugs are unique and carry the mark.
        $this->assertNone(DB::table('activities')->where('status', 'published')->whereNull('published_at')->limit(3)->pluck('slug'), 'A published activity has a publication date');
        $this->assertNone(DB::table('activities')->where('status', 'rejected')->whereNull('rejection_reason')->limit(3)->pluck('slug'), 'A rejected activity has a reason');
        $this->assertNone(DB::table('activities')->whereNotNull('deleted_at')->where('status', 'published')->limit(3)->pluck('slug'), 'A deleted activity is never published');
        $this->assertGreaterThanOrEqual(12, (int) DB::table('activities')->where('slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->min('max_participants'), 'Every bulk activity holds at least 12 (the capacity rule below relies on it)');
    }

    public function test_every_published_activity_is_complete_and_can_be_booked(): void
    {
        $published = fn () => DB::table('activities as a')->where('a.slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->where('a.status', 'published')->whereNull('a.deleted_at');

        $this->assertNone(
            $published()->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('activity_booking_options as o')->join('activity_pricing_rules as r', 'r.activity_booking_option_id', '=', 'o.id')
                ->whereColumn('o.activity_id', 'a.id')->where('o.is_active', 1)->whereNull('o.deleted_at')->where('r.is_active', 1))->limit(3)->pluck('a.slug'),
            'A published activity has an active booking option with a price'
        );
        $this->assertNone(
            $published()->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('activity_schedule_periods as s')->whereColumn('s.activity_id', 'a.id')->where('s.status', 'active')->whereNull('s.deleted_at'))->limit(3)->pluck('a.slug'),
            'A published activity has an active departure'
        );
        $this->assertNone(
            $published()->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('activity_images as i')->whereColumn('i.activity_id', 'a.id'))->limit(3)->pluck('a.slug'),
            'A published activity has photos'
        );
        // Each activity has exactly one default option and exactly one cover photo.
        $this->assertNone(DB::table('activity_booking_options')->where('is_default', 1)->groupBy('activity_id')->havingRaw('count(*) > 1')->pluck('activity_id'), 'One default option per activity');
        $this->assertNone(DB::table('activity_images')->where('is_cover', 1)->groupBy('activity_id')->havingRaw('count(*) > 1')->pluck('activity_id'), 'One cover photo per activity');
        $this->assertNone(DB::table('activity_images')->groupBy('activity_id')->havingRaw('sum(is_cover) = 0')->pluck('activity_id'), 'Every activity with photos has a cover');
    }

    public function test_pricing_rules_match_the_booking_model_they_price(): void
    {
        // A per-person option is priced by participant type and always has an adult price; every other model has one flat price.
        $this->assertNone(
            DB::table('activity_booking_options as o')->where('o.booking_model', 'per_person')->whereNull('o.deleted_at')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('activity_pricing_rules as r')->whereColumn('r.activity_booking_option_id', 'o.id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('activity_pricing_rules as r')->whereColumn('r.activity_booking_option_id', 'o.id')->where('r.participant_type', 'adult'))->limit(3)->pluck('o.id'),
            'A per-person option has an adult price'
        );
        $this->assertNone(
            DB::table('activity_pricing_rules as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')
                ->where('o.booking_model', '!=', 'per_person')->whereNotNull('r.participant_type')->limit(3)->pluck('r.id'),
            'Only per-person options are priced by participant type'
        );
        $this->assertNone(DB::table('activity_pricing_rules')->where('price', '<=', 0)->limit(3)->pluck('id'), 'Prices are positive');
        $this->assertSame(0, DB::table('activity_pricing_rules')->where('currency', '!=', config('platform.currency'))->count());
        // A departure-specific price points at a departure of the SAME activity.
        $this->assertNone(
            DB::table('activity_pricing_rules as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->join('activity_schedule_periods as s', 's.id', '=', 'r.schedule_period_id')
                ->whereColumn('s.activity_id', '!=', 'o.activity_id')->limit(3)->pluck('r.id'),
            'A price for one departure belongs to a departure of the same activity'
        );
        // The option fields the model needs are present (the app rejects an hourly option without a minimum duration).
        $this->assertNone(DB::table('activity_booking_options')->whereIn('booking_model', ['per_hour', 'per_day', 'package'])->whereNull('min_duration')->limit(3)->pluck('id'), 'Hourly, daily and package options have a minimum duration');
        $this->assertNone(DB::table('activity_booking_options')->whereIn('booking_model', ['per_hour', 'per_day'])->whereNull('max_duration')->limit(3)->pluck('id'), 'Hourly and daily options have a maximum duration');
    }

    public function test_feature_values_belong_to_the_activitys_own_category_and_use_the_right_column(): void
    {
        $this->assertNone(
            DB::table('activity_feature_values as v')->join('activities as a', 'a.id', '=', 'v.activity_id')->join('feature_definitions as d', 'd.id', '=', 'v.feature_definition_id')
                ->whereColumn('d.category_id', '!=', 'a.category_id')->limit(3)->pluck('v.id'),
            "A feature value must come from a definition of the activity's own category"
        );

        $columns = ['text' => 'value_text', 'select' => 'value_text', 'number' => 'value_number', 'boolean' => 'value_boolean', 'multi_select' => 'value_json'];
        $all = ['value_text', 'value_number', 'value_boolean', 'value_json'];
        $wrong = [];
        foreach ($columns as $type => $column) {
            $query = DB::table('activity_feature_values as v')->join('feature_definitions as d', 'd.id', '=', 'v.feature_definition_id')->where('d.type', $type);
            $query->where(function ($q) use ($column, $all) {
                $q->whereNull("v.{$column}");
                foreach (array_diff($all, [$column]) as $other) {
                    $q->orWhereNotNull("v.{$other}");
                }
            });
            $wrong = array_merge($wrong, $query->limit(3)->pluck('v.id')->all());
        }
        $this->assertNone($wrong, 'A value sits in the column of its definition type, and only there');

        // A select holds one of the definition's options; a multi-select holds a list of them.
        foreach (DB::table('activity_feature_values as v')->join('feature_definitions as d', 'd.id', '=', 'v.feature_definition_id')->whereIn('d.type', ['select', 'multi_select'])->limit(400)->get(['d.type', 'd.options', 'v.value_text', 'v.value_json']) as $row) {
            $allowed = array_column(json_decode($row->options, true) ?: [], 'key');
            $chosen = $row->type === 'select' ? [$row->value_text] : (json_decode($row->value_json, true) ?: []);
            $this->assertSame([], array_values(array_diff($chosen, $allowed)), 'A choice that is not one of the options');
            $this->assertNotEmpty($chosen);
        }

        // One value per activity and definition, and required attributes are always filled.
        $this->assertNone(
            DB::table('activities as a')->join('feature_definitions as d', 'd.category_id', '=', 'a.category_id')->where('a.slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->where('d.is_required', 1)->where('d.status', 'active')->whereNull('d.deleted_at')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('activity_feature_values as v')->whereColumn('v.activity_id', 'a.id')->whereColumn('v.feature_definition_id', 'd.id'))->limit(3)->pluck('a.slug'),
            'A required attribute is filled for every activity of its category'
        );
    }

    // --- Reservations -----------------------------------------------------------------------------------

    public function test_reservation_states_are_coherent(): void
    {
        $today = $this->today();
        $now = Carbon::now()->toDateTimeString();

        $rules = [
            'A completed booking is in the past' => DB::table('reservations')->where('status', 'completed')->where('reservation_date', '>=', $today),
            'A confirmed booking is today or later' => DB::table('reservations')->where('status', 'confirmed')->where('reservation_date', '<', $today),
            'A pending booking still holds its slot' => DB::table('reservations')->where('status', 'pending')->where(fn ($q) => $q->whereNull('hold_expires_at')->orWhere('hold_expires_at', '<=', $now)),
            'An expired booking has the hold that ran out' => DB::table('reservations')->where('status', 'expired')->where(fn ($q) => $q->whereNull('hold_expires_at')->orWhere('hold_expires_at', '>', $now)),
            'Confirmed and completed bookings have a confirmation time' => DB::table('reservations')->whereIn('status', ['confirmed', 'completed'])->whereNull('confirmed_at'),
            'Pending and expired bookings were never confirmed' => DB::table('reservations')->whereIn('status', ['pending', 'expired'])->whereNotNull('confirmed_at'),
            'A cancelled booking has a cancellation time' => DB::table('reservations')->where('status', 'cancelled')->whereNull('cancelled_at'),
            'Only a cancelled booking has a cancellation time' => DB::table('reservations')->where('status', '!=', 'cancelled')->whereNotNull('cancelled_at'),
            'Confirmation comes after creation' => DB::table('reservations')->whereNotNull('confirmed_at')->whereColumn('confirmed_at', '<', 'created_at'),
            'Cancellation comes after creation' => DB::table('reservations')->whereNotNull('cancelled_at')->whereColumn('cancelled_at', '<', 'created_at'),
        ];

        foreach ($rules as $rule => $query) {
            $this->assertNone($query->limit(3)->get(['uuid', 'status', 'reservation_date', 'created_at']), $rule);
        }

        $statuses = DB::table('reservations')->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        foreach (['completed', 'confirmed', 'cancelled', 'expired'] as $status) {
            $this->assertGreaterThan(0, $statuses[$status] ?? 0, "No {$status} reservations at all");
        }
    }

    public function test_reservations_use_real_options_departures_and_weekdays(): void
    {
        $this->assertNone(
            DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->whereColumn('o.activity_id', '!=', 'r.activity_id')->limit(3)->pluck('r.uuid'),
            'The booked option belongs to the booked activity'
        );
        $this->assertNone(
            DB::table('reservations as r')->join('activity_schedule_periods as s', 's.id', '=', 'r.schedule_period_id')->whereColumn('s.activity_id', '!=', 'r.activity_id')->limit(3)->pluck('r.uuid'),
            'The booked departure belongs to the booked activity'
        );
        $this->assertNone(
            DB::table('reservations as r')->join('customers as c', 'c.id', '=', 'r.customer_id')->join('users as u', 'u.id', '=', 'c.user_id')->where(fn ($q) => $q->where('u.status', '!=', 'active')->orWhereNotNull('u.deleted_at'))->limit(3)->pluck('r.uuid'),
            'Nobody who was suspended or deleted made a booking'
        );

        // The day of the trip falls on the departure's weekday (0 = Sunday, as the app stores it).
        $wrong = [];
        foreach (DB::table('reservations as r')->join('activity_schedule_periods as s', 's.id', '=', 'r.schedule_period_id')->orderBy('r.id')->limit(5000)->get(['r.uuid', 'r.reservation_date', 's.day_of_week']) as $row) {
            if (Carbon::parse($row->reservation_date)->dayOfWeek !== (int) $row->day_of_week) {
                $wrong[] = (array) $row;
            }
        }
        $this->assertNone($wrong, 'The trip is on the weekday of its departure');

        $this->assertNone(
            DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->whereColumn('r.created_at', '<', 'a.created_at')->limit(3)->pluck('r.uuid'),
            'Nobody booked an activity before it existed'
        );
    }

    public function test_reservation_prices_are_computed_from_the_pricing_rules_and_add_up(): void
    {
        $rules = [];
        foreach (DB::table('activity_pricing_rules')->whereNull('schedule_period_id')->get(['activity_booking_option_id', 'participant_type', 'price']) as $rule) {
            $rules[$rule->activity_booking_option_id][$rule->participant_type ?? 'flat'] = (float) $rule->price;
        }

        $wrong = [];
        $count = 0;
        foreach (DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->orderBy('r.id')
            ->get(['r.uuid', 'r.participants', 'r.quantity', 'r.duration', 'r.total', 'r.subtotal', 'r.discount', 'r.tax', 'r.pricing_breakdown', 'r.pricing_model', 'r.currency', 'r.activity_booking_option_id', 'o.booking_model']) as $r) {
            $count++;
            $breakdown = json_decode($r->pricing_breakdown, true);
            $sum = round(array_sum(array_column($breakdown, 'total')), 2);

            $problems = [];
            $r->pricing_model === $r->booking_model || $problems[] = 'pricing model differs from the option';
            abs($sum - (float) $r->subtotal) < 0.005 || $problems[] = "breakdown sums to {$sum}, subtotal is {$r->subtotal}";
            // What the customer pays is the subtotal less any coupon (the provider funds it); there is no tax.
            abs((float) $r->subtotal - (float) $r->discount + (float) $r->tax - (float) $r->total) < 0.005 || $problems[] = 'total is not subtotal - discount + tax';
            ((float) $r->discount >= 0.0 && (float) $r->tax === 0.0) || $problems[] = 'unexpected discount or tax';
            $r->currency === config('platform.currency') || $problems[] = 'currency';

            foreach ($breakdown as $line) {
                abs($line['quantity'] * $line['unit_price'] - $line['total']) < 0.005 || $problems[] = 'a line does not multiply out';
                $key = $line['participant_type'] ?? 'flat';
                // Each line prices at the option's rule (a per-person line by its participant type).
                if (isset($rules[$r->activity_booking_option_id][$key]) && abs($rules[$r->activity_booking_option_id][$key] - (float) $line['unit_price']) > 0.005) {
                    $problems[] = "unit price {$line['unit_price']} is not the rule's {$rules[$r->activity_booking_option_id][$key]}";
                }
            }

            if ($r->booking_model === 'per_person') {
                $participants = json_decode($r->participants, true);
                $problems[] = ($participants && array_sum($participants) === array_sum(array_column($breakdown, 'quantity')) && ($participants['adult'] ?? 0) >= 1) ? null : 'participants do not match the breakdown';
            }
            if ($r->booking_model === 'per_unit' && ($r->quantity ?? 0) < 1) {
                $problems[] = 'per-unit booking without a quantity';
            }
            if (in_array($r->booking_model, ['per_hour', 'per_day'], true) && ($r->duration ?? 0) < 1) {
                $problems[] = 'timed booking without a duration';
            }

            if ($problems = array_values(array_filter($problems))) {
                $wrong[] = ['uuid' => $r->uuid, 'problems' => $problems];
            }
        }

        $this->assertGreaterThan(0, $count);
        $this->assertNone($wrong, 'A reservation is priced from the option\'s own rules and its breakdown adds up');
    }

    public function test_no_day_is_overbooked(): void
    {
        $size = function (object $r): int {
            return match ($r->booking_model) {
                'per_person' => array_sum(json_decode($r->participants, true) ?: [1]),
                'per_unit' => (int) $r->quantity,
                default => 4,
            };
        };

        $load = [];
        $capacity = [];
        foreach (DB::table('reservations as r')->join('activity_booking_options as o', 'o.id', '=', 'r.activity_booking_option_id')->join('activities as a', 'a.id', '=', 'r.activity_id')
            ->whereIn('r.status', ['confirmed', 'completed', 'pending'])
            ->get(['r.activity_id', 'r.reservation_date', 'r.participants', 'r.quantity', 'o.booking_model', 'a.max_participants']) as $r) {
            $key = $r->activity_id.'|'.$r->reservation_date;
            $load[$key] = ($load[$key] ?? 0) + $size($r);
            $capacity[$key] = $r->max_participants;
        }

        $over = [];
        foreach ($load as $key => $people) {
            if ($people > $capacity[$key]) {
                $over[] = ['activity|day' => $key, 'booked' => $people, 'capacity' => $capacity[$key]];
            }
        }
        $this->assertNone($over, 'No activity is booked beyond its capacity on any day');
    }

    public function test_every_reservation_slot_has_its_capacity_lock(): void
    {
        $missing = DB::table('reservations as r')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reservation_capacity_locks as l')
                ->whereColumn('l.activity_booking_option_id', 'r.activity_booking_option_id')
                ->whereRaw('l.schedule_period_id = COALESCE(r.schedule_period_id, 0)')
                ->whereColumn('l.reservation_date', 'r.reservation_date'))
            ->limit(3)->pluck('r.uuid');

        $this->assertNone($missing, 'Every option, departure and day that was booked has its capacity lock');
    }

    // --- Payments ----------------------------------------------------------------------------------------

    public function test_payments_agree_with_their_reservations(): void
    {
        $paid = ['succeeded', 'refunded', 'partially_refunded'];

        // Amounts, currency and refunds.
        $this->assertNone(DB::table('payments as p')->join('reservations as r', 'r.id', '=', 'p.reservation_id')->whereRaw('ABS(p.amount - r.total) > 0.005')->limit(3)->pluck('p.reference_id'), 'A payment is for the booking total');
        $this->assertNone(DB::table('payments as p')->join('reservations as r', 'r.id', '=', 'p.reservation_id')->whereColumn('p.currency', '!=', 'r.currency')->limit(3)->pluck('p.reference_id'), 'A payment is in the booking currency');
        $this->assertNone(DB::table('payments')->whereColumn('refunded_amount', '>', 'amount')->limit(3)->pluck('reference_id'), 'Nothing is refunded beyond what was paid');
        $this->assertNone(DB::table('payments')->where('status', 'refunded')->whereRaw('ABS(refunded_amount - amount) > 0.005')->limit(3)->pluck('reference_id'), 'A refunded payment was refunded in full');
        $this->assertNone(DB::table('payments')->where('status', 'partially_refunded')->where(fn ($q) => $q->where('refunded_amount', '<=', 0)->orWhereColumn('refunded_amount', '>=', 'amount'))->limit(3)->pluck('reference_id'), 'A partly refunded payment was refunded in part');
        $this->assertNone(DB::table('payments')->whereIn('status', ['succeeded', 'failed', 'pending'])->where('refunded_amount', '>', 0)->limit(3)->pluck('reference_id'), 'Only refunded payments have a refunded amount');
        $this->assertNone(DB::table('payments')->where('status', 'failed')->whereNull('failure_reason')->limit(3)->pluck('reference_id'), 'A failed payment says why');
        $this->assertNone(DB::table('payments')->whereIn('status', $paid)->whereNull('paid_at')->limit(3)->pluck('reference_id'), 'A paid payment has a payment time');
        $this->assertNone(DB::table('payments')->whereNotIn('status', $paid)->whereNotNull('paid_at')->limit(3)->pluck('reference_id'), 'An unpaid payment has no payment time');
        $this->assertSame(DB::table('payments')->count(), DB::table('payments')->distinct()->count('reference_id'), 'Payment references are unique');

        // Reservation state decides whether it was paid.
        $paidCount = fn (string $reservationCondition) => DB::table('reservations as r')->whereRaw($reservationCondition)
            ->whereRaw('(select count(*) from payments p where p.reservation_id = r.id and p.status in (\'succeeded\',\'refunded\',\'partially_refunded\')) != 1');

        $this->assertNone($paidCount("r.status in ('confirmed','completed')")->limit(3)->pluck('r.uuid'), 'A confirmed or completed booking has exactly one paid payment');
        $this->assertNone($paidCount("r.status = 'cancelled' and r.confirmed_at is not null")->limit(3)->pluck('r.uuid'), 'A booking cancelled after being confirmed was paid once');
        $this->assertNone(
            DB::table('reservations as r')->where(fn ($q) => $q->whereIn('r.status', ['expired', 'pending'])->orWhere(fn ($w) => $w->where('r.status', 'cancelled')->whereNull('r.confirmed_at')))
                ->whereRaw('(select count(*) from payments p where p.reservation_id = r.id and p.status in (\'succeeded\',\'refunded\',\'partially_refunded\')) > 0')->limit(3)->pluck('r.uuid'),
            'A booking that was never confirmed was never paid'
        );
        // Only a cancelled booking was refunded in full, and a confirmed or completed one at most in part.
        $this->assertNone(DB::table('payments as p')->join('reservations as r', 'r.id', '=', 'p.reservation_id')->where('p.status', 'refunded')->where('r.status', '!=', 'cancelled')->limit(3)->pluck('p.reference_id'), 'Only a cancelled booking is refunded in full');
    }

    // --- Reviews -----------------------------------------------------------------------------------------

    public function test_reviews_only_follow_completed_bookings_and_ratings_are_kept_in_step(): void
    {
        $this->assertNone(
            DB::table('reviews as v')->join('reservations as r', 'r.id', '=', 'v.reservation_id')
                ->where(fn ($q) => $q->where('r.status', '!=', 'completed')->orWhereColumn('r.customer_id', '!=', 'v.customer_id')->orWhereColumn('r.activity_id', '!=', 'v.activity_id'))->limit(3)->pluck('v.uuid'),
            'A review comes from the customer who COMPLETED a booking of that activity'
        );
        $this->assertNone(DB::table('reviews')->groupBy('customer_id', 'activity_id')->havingRaw('count(*) > 1')->pluck('activity_id'), 'One review per customer and activity');
        $this->assertNone(DB::table('reviews')->where(fn ($q) => $q->where('rating', '<', 1)->orWhere('rating', '>', 5))->limit(3)->pluck('uuid'), 'Ratings are 1 to 5');
        $this->assertNone(DB::table('reviews')->where('is_hidden', 1)->where(fn ($q) => $q->whereNull('hidden_reason')->orWhereNull('hidden_at'))->limit(3)->pluck('uuid'), 'A hidden review says why and when');
        $this->assertNone(DB::table('reviews')->where('is_hidden', 0)->where(fn ($q) => $q->whereNotNull('hidden_reason')->orWhereNotNull('hidden_at'))->limit(3)->pluck('uuid'), 'A visible review has no hiding details');
        $this->assertNone(DB::table('reviews as v')->join('reservations as r', 'r.id', '=', 'v.reservation_id')->whereRaw('date(v.created_at) < r.reservation_date')->limit(3)->pluck('v.uuid'), 'Nobody reviews a trip before taking it');

        // Every activity's rating is the average of its VISIBLE reviews, as ReviewService keeps it.
        $stats = DB::table('reviews')->where('is_hidden', 0)->groupBy('activity_id')->selectRaw('activity_id, avg(rating) as average, count(*) as total')->get()->keyBy('activity_id');
        $wrong = [];
        foreach (DB::table('activities')->where('slug', 'like', '%'.BulkSeeder::ACTIVITY_SLUG_MARK.'%')->get(['id', 'average_rating', 'reviews_count']) as $activity) {
            $expectedAverage = isset($stats[$activity->id]) ? round((float) $stats[$activity->id]->average, 2) : 0.0;
            $expectedCount = isset($stats[$activity->id]) ? (int) $stats[$activity->id]->total : 0;
            if (abs((float) $activity->average_rating - $expectedAverage) > 0.005 || (int) $activity->reviews_count !== $expectedCount) {
                $wrong[] = ['activity' => $activity->id, 'stored' => [$activity->average_rating, $activity->reviews_count], 'expected' => [$expectedAverage, $expectedCount]];
            }
        }
        $this->assertNone($wrong, "An activity's rating and review count match its visible reviews");
        $this->assertGreaterThan(0, DB::table('activities')->where('reviews_count', '>', 0)->count());
    }

    // --- Everything else ------------------------------------------------------------------------------------

    public function test_notifications_are_the_apps_own_and_reach_the_right_people(): void
    {
        $classes = DB::table('notifications')->distinct()->pluck('type');
        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "{$class} is not a notification class of this app");
        }

        $this->assertNone(DB::table('notifications as n')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.id', 'n.notifiable_id'))->limit(3)->pluck('n.id'), 'Every notification belongs to a user');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', '!=', 'App\\Models\\User')->count());
        $this->assertNone(DB::table('notifications')->whereNotNull('read_at')->whereColumn('read_at', '<', 'created_at')->limit(3)->pluck('id'), 'Nothing is read before it exists');

        // A notice that only makes sense to one account reaches someone who has that account (the two feeds depend on it).
        foreach ([[NotificationFeed::CUSTOMER_ONLY, 'customers'], [NotificationFeed::PROVIDER_ONLY, 'providers']] as [$only, $table]) {
            $this->assertNone(
                DB::table('notifications as n')->whereIn('n.type', $only)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($table)->whereColumn("{$table}.user_id", 'n.notifiable_id'))->limit(3)->pluck('n.id'),
                "A notice for the {$table} account reaches someone without one"
            );
        }

        // Notices about a reservation point at a reservation that exists, so the admin inbox links open something.
        $uuids = [];
        foreach (DB::table('notifications')->where('data', 'like', '%reservation_uuid%')->limit(400)->pluck('data') as $data) {
            $uuids[] = json_decode($data, true)['reservation_uuid'];
        }
        $this->assertNotEmpty($uuids);
        $this->assertSame(count(array_unique($uuids)), DB::table('reservations')->whereIn('uuid', array_unique($uuids))->count(), 'A notification points at a reservation that does not exist');
    }

    public function test_the_sign_in_history_cannot_sign_anyone_in(): void
    {
        $now = Carbon::now()->toDateTimeString();
        $bulk = fn () => DB::table('otps')->where(fn ($q) => $q->where('phone', 'like', BulkSeeder::CUSTOMER_PHONE_PREFIX.'%')->orWhere('phone', 'like', BulkSeeder::PROVIDER_PHONE_PREFIX.'%'));

        $this->assertSame(0, $bulk()->where('expires_at', '>', $now)->count(), 'Every historical code has expired');
        $this->assertSame(0, $bulk()->where('code', (string) config('otp.default_code'))->count(), 'None of them is the fixed default code');
        $this->assertSame(0, $bulk()->whereNotNull('consumed_at')->whereColumn('consumed_at', '<', 'created_at')->count());
        $this->assertSame(0, $bulk()->where('type', 'login')->whereNull('user_id')->count(), 'A login code belongs to a user');
        $this->assertSame(0, $bulk()->where('type', 'registration')->whereNotNull('user_id')->count(), 'A registration code belongs to nobody yet');
        $this->assertSame(0, $bulk()->whereNotIn('type', ['login', 'registration'])->count());
    }

    public function test_devices_favorites_and_broadcasts_are_sensible(): void
    {
        // Devices
        $this->assertSame(DB::table('devices')->count(), DB::table('devices')->distinct()->count('fcm_token'), 'Push tokens are unique');
        $this->assertSame(0, DB::table('devices')->whereNotIn('platform', ['ios', 'android', 'web'])->count());
        $this->assertNone(DB::table('devices as d')->join('users as u', 'u.id', '=', 'd.user_id')->where(fn ($q) => $q->where('u.status', '!=', 'active')->orWhereNotNull('u.deleted_at'))->limit(3)->pluck('d.id'), 'No device belongs to a suspended or deleted user');

        // Favourites: published, live activities and live customers only.
        $this->assertNone(DB::table('favorites as f')->join('activities as a', 'a.id', '=', 'f.activity_id')->where(fn ($q) => $q->where('a.status', '!=', 'published')->orWhereNotNull('a.deleted_at'))->limit(3)->pluck('f.id'), 'Only published activities are favourited');
        $this->assertNone(DB::table('favorites')->groupBy('customer_id', 'activity_id')->havingRaw('count(*) > 1')->pluck('activity_id'), 'A favourite is saved once');

        // Broadcasts
        $staff = DB::table('users')->where('phone', 'like', BulkSeeder::STAFF_PHONE_PREFIX.'%')->pluck('id');
        $this->assertNone(DB::table('broadcasts')->whereNotIn('created_by', $staff)->limit(3)->pluck('uuid'), 'Staff wrote every announcement');
        $this->assertNone(DB::table('broadcasts')->where('status', 'draft')->where(fn ($q) => $q->where('recipients_count', '>', 0)->orWhere('delivered_count', '>', 0)->orWhereNotNull('sent_at'))->limit(3)->pluck('uuid'), 'A draft has not been sent');
        $this->assertNone(DB::table('broadcasts')->where('status', 'sent')->where(fn ($q) => $q->whereNull('sent_at')->orWhereColumn('delivered_count', '>', 'recipients_count')->orWhere('delivered_count', '<=', 0))->limit(3)->pluck('uuid'), 'A sent announcement reached someone, never more people than it had');
        $this->assertNone(DB::table('broadcasts')->where('status', 'failed')->whereNull('failed_reason')->limit(3)->pluck('uuid'), 'A failed announcement says why');
        $this->assertNone(DB::table('broadcasts')->where('status', 'sending')->limit(3)->pluck('uuid'), 'None is stuck "sending" (it could never be deleted)');
        $this->assertNone(DB::table('broadcasts')->where('audience', 'user')->whereNull('recipient_user_id')->limit(3)->pluck('uuid'), 'A one-person announcement names the person');
        $this->assertNone(DB::table('broadcasts')->where('audience', '!=', 'user')->whereNotNull('recipient_user_id')->limit(3)->pluck('uuid'), 'An audience announcement names nobody');
    }
}
