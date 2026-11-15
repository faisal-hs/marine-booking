<?php

namespace Tests\Feature\Seeders;

use App\Models\Customer;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\Activities\ActivitySuspendedNotification;
use App\Notifications\Activities\ActivitySuspensionLiftedNotification;
use App\Notifications\Broadcasts\BroadcastNotification;
use App\Notifications\Payments\PaymentFailedNotification;
use App\Notifications\Payments\PaymentRefundedNotification;
use App\Notifications\Providers\ProviderDocumentReviewedNotification;
use App\Notifications\Providers\ProviderProfileChangeReviewedNotification;
use App\Notifications\Providers\ProviderRejectedNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Notifications\Reservations\Provider\BookingCancelledProviderNotification;
use App\Notifications\Reservations\Provider\NewBookingProviderNotification;
use App\Notifications\Reservations\ReservationCancelledNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Notifications\Reservations\ReservationCreatedNotification;
use App\Notifications\Reservations\ReservationExpiredNotification;
use App\Notifications\Wallet\WithdrawalStatusNotification;
use App\Services\NotificationFeed;
use Carbon\Carbon;
use Database\Seeders\NotificationsDemoSeeder;
use Database\Seeders\ProviderAnalyticsDemoSeeder;
use Database\Seeders\ProviderWithdrawalsDemoSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\Concerns\UsesSeededDemoDatabase;
use Tests\TestCase;

/**
 * NotificationsDemoSeeder gives every customer and every provider five of each kind of notification the app sends them, each made by the app's own notification
 * class. Built once for the class: the demo data, bookings (the analytics demo data), withdrawals, and then the notifications.
 */
class NotificationsDemoSeederTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use UsesSeededDemoDatabase;

    /** What each kind is stored as: the app's class. */
    private const CLASS_OF = [
        'reservation.created' => ReservationCreatedNotification::class, 'reservation.confirmed' => ReservationConfirmedNotification::class, 'reservation.cancelled' => ReservationCancelledNotification::class,
        'reservation.expired' => ReservationExpiredNotification::class, 'payment.failed' => PaymentFailedNotification::class, 'payment.refunded' => PaymentRefundedNotification::class,
        'provider.booking.confirmed' => NewBookingProviderNotification::class, 'provider.booking.cancelled' => BookingCancelledProviderNotification::class,
        'provider.document.approved' => ProviderDocumentReviewedNotification::class, 'provider.document.rejected' => ProviderDocumentReviewedNotification::class,
        'provider.document.update_requested' => ProviderDocumentReviewedNotification::class, 'activity.suspended' => ActivitySuspendedNotification::class,
        'activity.suspension_lifted' => ActivitySuspensionLiftedNotification::class, 'provider.profile_change.approved' => ProviderProfileChangeReviewedNotification::class,
        'provider.profile_change.rejected' => ProviderProfileChangeReviewedNotification::class, 'provider.withdrawal.processing' => WithdrawalStatusNotification::class,
        'provider.withdrawal.paid' => WithdrawalStatusNotification::class, 'provider.withdrawal.rejected' => WithdrawalStatusNotification::class,
        'provider.withdrawal.failed' => WithdrawalStatusNotification::class, 'provider.verified' => ProviderVerifiedNotification::class, 'provider.rejected' => ProviderRejectedNotification::class,
        'broadcast' => BroadcastNotification::class,
    ];

    private const CUSTOMER_KINDS = ['reservation.created', 'reservation.confirmed', 'reservation.cancelled', 'reservation.expired', 'payment.failed', 'payment.refunded', 'broadcast'];

    private const PROVIDER_KINDS = ['provider.booking.confirmed', 'provider.booking.cancelled', 'provider.document.approved', 'provider.document.rejected', 'provider.document.update_requested',
        'activity.suspended', 'activity.suspension_lifted', 'provider.profile_change.approved', 'provider.profile_change.rejected', 'provider.withdrawal.processing', 'provider.withdrawal.paid',
        'provider.withdrawal.rejected', 'provider.withdrawal.failed', 'provider.verified', 'provider.rejected', 'broadcast'];

    /** The fields each family of notification has, as the app's own classes build them. */
    private const FIELDS = [
        'reservation' => ['type', 'reservation_id', 'reservation_uuid', 'activity_title', 'total', 'currency', 'message'],
        'booking' => ['type', 'reservation_id', 'reservation_uuid', 'activity_title', 'total', 'currency', 'message', 'reservation_date', 'participants', 'quantity'],
        'payment' => ['type', 'payment_id', 'payment_uuid', 'reference_id', 'amount', 'currency', 'message'],
        'document' => ['type', 'document', 'note', 'message'],
        'activity' => ['type', 'activity_id', 'activity_uuid', 'activity_title', 'message'],
        'change' => ['type', 'provider_profile_change_uuid', 'message'],
        'withdrawal' => ['type', 'withdrawal_uuid', 'status', 'message'],
        'verified' => ['type', 'provider_id', 'provider_uuid', 'business_name', 'message'],
        'rejected' => ['type', 'provider_id', 'provider_uuid', 'business_name', 'message', 'rejection_reason'],
        'broadcast' => ['type', 'title', 'message', 'broadcast_uuid'],
    ];

    protected function seedDemo(): void
    {
        $this->seed(ProviderAnalyticsDemoSeeder::class);
        $this->seed(ProviderWithdrawalsDemoSeeder::class);
        $this->seed(NotificationsDemoSeeder::class);
    }

    private function familyOf(string $kind): string
    {
        return match (true) {
            $kind === 'provider.booking.confirmed', $kind === 'provider.booking.cancelled' => 'booking',
            str_starts_with($kind, 'reservation.') => 'reservation',
            str_starts_with($kind, 'payment.') => 'payment',
            str_starts_with($kind, 'provider.document.') => 'document',
            str_starts_with($kind, 'activity.') => 'activity',
            str_starts_with($kind, 'provider.profile_change.') => 'change',
            str_starts_with($kind, 'provider.withdrawal.') => 'withdrawal',
            $kind === 'provider.verified' => 'verified',
            $kind === 'provider.rejected' => 'rejected',
            default => 'broadcast',
        };
    }

    /** @return Collection<int, object> every notification, with its data decoded and its kind */
    private function all()
    {
        return DB::table('notifications')->get()->map(function ($n) {
            $n->payload = json_decode($n->data, true);
            $n->kind = $n->payload['type'];

            return $n;
        });
    }

    private function activeCustomerUsers(): array
    {
        return DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->whereNull('users.deleted_at')->where('users.status', 'active')->pluck('users.id')->map(fn ($i) => (int) $i)->all();
    }

    private function activeProviderUsers(): array
    {
        return DB::table('providers')->join('users', 'users.id', '=', 'providers.user_id')->whereNull('users.deleted_at')->where('users.status', 'active')->whereNull('providers.deleted_at')->pluck('users.id')->map(fn ($i) => (int) $i)->all();
    }

    // --- Five of every kind ---------------------------------------------------------------------------------------------

    public function test_every_customer_has_exactly_five_of_each_of_their_seven_kinds(): void
    {
        $all = $this->all();
        $customers = $this->activeCustomerUsers();
        $this->assertGreaterThan(10, count($customers));

        foreach ($customers as $userId) {
            $mine = $all->where('notifiable_id', $userId)->whereIn('kind', self::CUSTOMER_KINDS);
            $this->assertCount(35, $mine, "Customer user {$userId}");
            foreach (self::CUSTOMER_KINDS as $kind) {
                $this->assertSame(5, $mine->where('kind', $kind)->count(), "{$kind} for {$userId}");
            }
        }
    }

    public function test_every_provider_has_exactly_five_of_each_of_their_sixteen_kinds(): void
    {
        $all = $this->all();
        $providers = $this->activeProviderUsers();
        $this->assertGreaterThan(10, count($providers));

        foreach ($providers as $userId) {
            $mine = $all->where('notifiable_id', $userId)->whereIn('kind', self::PROVIDER_KINDS);
            $this->assertCount(80, $mine, "Provider user {$userId}");
            foreach (self::PROVIDER_KINDS as $kind) {
                $this->assertSame(5, $mine->where('kind', $kind)->count(), "{$kind} for {$userId}");
            }
        }
    }

    public function test_the_total_is_exactly_what_that_makes_and_nobody_else_gets_any(): void
    {
        $customers = $this->activeCustomerUsers();
        $providers = $this->activeProviderUsers();

        $this->assertSame(count($customers) * 35 + count($providers) * 80, DB::table('notifications')->count(), 'Nobody is in both lists, so these add up');
        $this->assertSame(0, DB::table('notifications')->whereNotIn('notifiable_id', array_merge($customers, $providers))->count(), 'Staff, and people who are not active, get none');
        $this->assertSame([User::class], DB::table('notifications')->distinct()->pluck('notifiable_type')->all());
    }

    public function test_there_are_no_admin_notifications_and_no_kind_that_is_not_listed(): void
    {
        $this->assertSame(0, DB::table('notifications')->where('type', 'like', '%\\Admin\\%')->count());

        $expected = collect(array_merge(self::CUSTOMER_KINDS, self::PROVIDER_KINDS))->unique()->sort()->values()->all();
        $actual = $this->all()->pluck('kind')->unique()->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    // --- Made by the app's own classes ----------------------------------------------------------------------------------

    public function test_each_kind_is_stored_under_the_class_the_app_uses_for_it(): void
    {
        foreach ($this->all()->groupBy('kind') as $kind => $rows) {
            $this->assertSame([self::CLASS_OF[$kind]], $rows->pluck('type')->unique()->all(), $kind);
        }
    }

    public function test_each_has_the_fields_the_app_gives_that_kind_and_a_message(): void
    {
        foreach ($this->all() as $n) {
            $expected = self::FIELDS[$this->familyOf($n->kind)];
            $keys = array_keys($n->payload);
            sort($expected);
            sort($keys);

            $this->assertSame($expected, $keys, $n->kind);
            $this->assertNotSame('', trim((string) $n->payload['message']), $n->kind);
        }
    }

    public function test_the_messages_say_what_the_kind_means(): void
    {
        $sample = fn (string $kind) => $this->all()->where('kind', $kind)->first()->payload['message'];

        $this->assertStringContainsString('withdrawal', strtolower($sample('provider.withdrawal.paid')));
        $this->assertStringContainsString('approved', $sample('provider.document.approved'));
        $this->assertStringContainsString('rejected', $sample('provider.document.rejected'));
        $this->assertStringContainsString('update', $sample('provider.document.update_requested'));
        $this->assertStringContainsString('suspended', $sample('activity.suspended'));
        $this->assertStringContainsString('not approved', $sample('provider.profile_change.rejected'));
        $this->assertStringContainsString('approved', $sample('provider.profile_change.approved'));
    }

    // --- The two feeds are kept apart -------------------------------------------------------------------------------------

    public function test_a_customer_gets_no_provider_only_kind_and_a_provider_no_customer_only_kind(): void
    {
        $customers = $this->activeCustomerUsers();
        $providers = $this->activeProviderUsers();

        $this->assertSame(0, DB::table('notifications')->whereIn('notifiable_id', $customers)->whereIn('type', NotificationFeed::PROVIDER_ONLY)->count());
        $this->assertSame(0, DB::table('notifications')->whereIn('notifiable_id', $providers)->whereIn('type', NotificationFeed::CUSTOMER_ONLY)->count());
    }

    // --- Real records where there are real records ----------------------------------------------------------------------

    public function test_a_customers_booking_notifications_point_at_their_own_bookings_of_the_right_status(): void
    {
        $expected = ['reservation.confirmed' => 'confirmed', 'reservation.cancelled' => 'cancelled', 'reservation.expired' => 'expired', 'reservation.created' => 'pending'];
        $checked = 0;

        foreach ($this->all()->whereIn('kind', array_keys($expected)) as $n) {
            if ($n->payload['reservation_id'] === null) {
                continue;   // a customer with no booking of that kind: made from a record that is not saved
            }
            $reservation = DB::table('reservations')->where('id', $n->payload['reservation_id'])->first();
            $customerUser = DB::table('customers')->where('id', $reservation->customer_id)->value('user_id');

            $this->assertSame((int) $n->notifiable_id, (int) $customerUser, 'Their own booking');
            $this->assertSame($n->payload['reservation_uuid'], $reservation->uuid);
            $this->assertSame($expected[$n->kind], $reservation->status, "{$n->kind} must open a booking that is {$expected[$n->kind]}");
            $checked++;
        }

        $this->assertGreaterThan(100, $checked, 'Most use real bookings');
    }

    public function test_where_a_customer_has_a_booking_of_that_status_it_is_the_one_used(): void
    {
        $withStatus = DB::table('reservations')->where('status', 'confirmed')->pluck('customer_id')->unique();
        $customerId = $withStatus->first();
        $userId = DB::table('customers')->where('id', $customerId)->value('user_id');

        $ids = $this->all()->where('notifiable_id', $userId)->where('kind', 'reservation.confirmed')->pluck('payload.reservation_id')->unique()->all();

        $this->assertNotContains(null, $ids);
        $this->assertSame([], array_diff($ids, DB::table('reservations')->where('customer_id', $customerId)->where('status', 'confirmed')->pluck('id')->all()));
    }

    public function test_a_customers_payment_notifications_point_at_their_own_payments_of_the_right_status(): void
    {
        $expected = ['payment.failed' => 'failed', 'payment.refunded' => 'refunded'];
        $real = 0;

        foreach ($this->all()->whereIn('kind', array_keys($expected)) as $n) {
            if ($n->payload['payment_id'] === null) {
                continue;
            }
            $payment = DB::table('payments as p')->join('reservations as r', 'r.id', '=', 'p.reservation_id')->join('customers as c', 'c.id', '=', 'r.customer_id')->where('p.id', $n->payload['payment_id'])
                ->first(['p.status', 'p.uuid', 'c.user_id']);

            $this->assertSame((int) $n->notifiable_id, (int) $payment->user_id);
            $this->assertSame($expected[$n->kind], $payment->status, "{$n->kind} must be about a payment that is {$expected[$n->kind]}");
            $this->assertSame($n->payload['payment_uuid'], $payment->uuid);
            $real++;
        }

        $this->assertGreaterThan(20, $real, 'Refunded payments exist (cancelled bookings were refunded), so many are real');
    }

    public function test_a_providers_booking_notifications_are_about_bookings_of_their_own_activities_of_the_right_kind(): void
    {
        foreach ($this->all()->whereIn('kind', ['provider.booking.confirmed', 'provider.booking.cancelled']) as $n) {
            if ($n->payload['reservation_id'] === null) {
                continue;
            }
            $booking = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')
                ->where('r.id', $n->payload['reservation_id'])->first(['p.user_id', 'r.status']);

            $this->assertSame((int) $n->notifiable_id, (int) $booking->user_id);
            $this->assertContains($booking->status, $n->kind === 'provider.booking.cancelled' ? ['cancelled'] : ['confirmed', 'completed']);
        }
    }

    public function test_a_providers_withdrawal_notifications_are_about_their_own_withdrawals_in_that_status(): void
    {
        $checked = 0;

        foreach ($this->all()->filter(fn ($n) => str_starts_with($n->kind, 'provider.withdrawal.')) as $n) {
            $withdrawal = DB::table('withdrawals')->where('uuid', $n->payload['withdrawal_uuid'])->first();
            if ($withdrawal === null) {
                continue;   // a provider with no withdrawal of that status: made from a record that is not saved
            }

            $this->assertSame((int) $n->notifiable_id, (int) DB::table('providers')->where('id', $withdrawal->provider_id)->value('user_id'));
            $this->assertSame($withdrawal->status, $n->payload['status']);
            $this->assertSame("provider.withdrawal.{$withdrawal->status}", $n->kind);
            $checked++;
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_a_person_with_no_record_of_a_kind_still_gets_five_of_it_with_nothing_to_open(): void
    {
        // No payment in the demo data has failed, so no customer has a real one to point at.
        $this->assertSame(0, DB::table('payments')->where('status', 'failed')->count());
        $user = DB::table('customers')->orderBy('id')->value('user_id');

        $rows = $this->all()->where('notifiable_id', $user)->where('kind', 'payment.failed');

        $this->assertCount(5, $rows);
        $this->assertTrue($rows->every(fn ($n) => $n->payload['payment_id'] === null && str_starts_with($n->payload['reference_id'], 'PAY-DEMO')));
    }

    // --- Dates and read state ------------------------------------------------------------------------------------------------

    public function test_every_notification_is_from_the_last_thirty_days_and_never_the_future(): void
    {
        $now = $this->demoSeededAt()->addHour()->toDateTimeString();

        $this->assertSame(0, DB::table('notifications')->where('created_at', '>', $now)->count());
        $this->assertSame(0, DB::table('notifications')->where('created_at', '<', $this->demoSeededAt()->subDays(31)->toDateTimeString())->count());
        $this->assertSame(0, DB::table('notifications')->whereNotNull('read_at')->where(fn ($q) => $q->where('read_at', '>', $now)->orWhereColumn('read_at', '<', 'created_at'))->count());
    }

    public function test_most_old_ones_are_read_and_most_recent_ones_are_not(): void
    {
        $edge = $this->demoSeededAt()->subDays(10)->toDateTimeString();
        $old = DB::table('notifications')->where('created_at', '<', $edge);
        $recent = DB::table('notifications')->where('created_at', '>=', $edge);

        $this->assertGreaterThan(0.75, (clone $old)->whereNotNull('read_at')->count() / (clone $old)->count());
        $this->assertLessThan(0.97, (clone $old)->whereNotNull('read_at')->count() / (clone $old)->count());
        $this->assertGreaterThan(0.10, (clone $recent)->whereNotNull('read_at')->count() / (clone $recent)->count());
        $this->assertLessThan(0.45, (clone $recent)->whereNotNull('read_at')->count() / (clone $recent)->count());
    }

    // --- What the app shows --------------------------------------------------------------------------------------------------

    public function test_the_customer_app_lists_a_customers_thirty_five_with_a_type_and_a_title_each(): void
    {
        $customer = Customer::query()->whereIn('user_id', $this->activeCustomerUsers())->orderBy('id')->first();
        $token = $this->tokenFor($customer->user, 'customer');

        $r = $this->getJson('/api/v1/customer-app/notifications?per_page=50', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertSame(35, $r->json('meta.total'));
        $byType = collect($r->json('data'))->groupBy('type')->map->count()->all();
        $this->assertSame(['booking' => 20, 'payment' => 10, 'general' => 5], $byType);
        $this->assertSame(0, collect($r->json('data'))->where('title', 'Notification')->whereIn('type', ['booking', 'payment'])->count(), 'Booking and payment kinds have their own titles');
    }

    public function test_the_home_screens_unread_count_is_the_number_not_yet_read(): void
    {
        $customer = Customer::query()->whereIn('user_id', $this->activeCustomerUsers())->orderBy('id')->first();
        $token = $this->tokenFor($customer->user, 'customer');
        $unread = DB::table('notifications')->where('notifiable_id', $customer->user_id)->whereNull('read_at')->count();

        $this->assertGreaterThan(0, $unread);
        $this->assertSame($unread, $this->getJson('/api/v1/customer-app/home', ['Authorization' => "Bearer {$token}"])->assertOk()->json('data.unread_notifications'));
    }

    public function test_the_provider_app_lists_a_providers_eighty_and_counts_the_unread(): void
    {
        $provider = Provider::query()->whereIn('user_id', $this->activeProviderUsers())->where('verification_status', 'verified')->orderBy('id')->first();
        $token = $this->tokenFor($provider->user, 'provider');
        $unread = DB::table('notifications')->where('notifiable_id', $provider->user_id)->whereNull('read_at')->count();

        $list = $this->hit($token, 'GET', '/api/v1/provider/notifications?per_page=100')->assertOk();

        $this->assertSame(80, $list->json('pagination.total_items'));
        $this->assertSame($unread, $this->hit($token, 'GET', '/api/v1/provider/notifications/unread-count')->assertOk()->json('data.count'));
    }

    public function test_marking_them_all_as_read_works_on_the_seeded_ones(): void
    {
        $customer = Customer::query()->whereIn('user_id', $this->activeCustomerUsers())->orderBy('id')->first();
        $token = $this->tokenFor($customer->user, 'customer');

        // Inside a transaction that is rolled back, so the shared data is left as it was.
        DB::beginTransaction();
        try {
            $marked = $this->postJson('/api/v1/customer-app/notifications/read-all', [], ['Authorization' => "Bearer {$token}"])->assertOk()->json('data.marked');
            $left = DB::table('notifications')->where('notifiable_id', $customer->user_id)->whereNull('read_at')->count();
        } finally {
            DB::rollBack();
        }

        $this->assertGreaterThan(0, $marked);
        $this->assertSame(0, $left);
    }

    // --- Running it again ------------------------------------------------------------------------------------------------

    public function test_running_it_again_adds_nothing(): void
    {
        $before = DB::table('notifications')->count();

        $this->seed(NotificationsDemoSeeder::class);
        $this->seed(NotificationsDemoSeeder::class);

        $this->assertSame($before, DB::table('notifications')->count());
    }

    public function test_it_makes_the_same_notifications_every_time_the_clock_being_the_same(): void
    {
        $snapshot = fn () => DB::table('notifications')->orderBy('id')->get(['id', 'type', 'notifiable_id', 'data', 'read_at', 'created_at'])->map(fn ($r) => (array) $r)->all();

        // Dated relative to now, so "the same" means with the same clock: it is pinned for both makings. Inside a transaction that is rolled back.
        Carbon::setTestNow($this->demoSeededAt()->addMinutes(5));
        DB::beginTransaction();
        try {
            DB::table('notifications')->delete();
            $this->seed(NotificationsDemoSeeder::class);
            $first = $snapshot();
            DB::table('notifications')->delete();
            $this->seed(NotificationsDemoSeeder::class);
            $again = $snapshot();
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }

        $this->assertNotEmpty($first);
        $this->assertSame($first, $again);
    }
}
