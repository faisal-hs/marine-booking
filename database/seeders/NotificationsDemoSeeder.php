<?php

namespace Database\Seeders;

use App\Enums\CancellationSource;
use App\Models\Activity;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\ProviderDocumentEvent;
use App\Models\ProviderProfileChange;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Withdrawal;
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
use Database\Seeders\Bulk\BulkSeeder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Notifications for every customer and every provider: FIVE of EACH kind of notification the app sends them, so the notification lists, their types, titles,
 * unread counts, "mark as read" and "mark all as read" all have something to work on. Run it on top of the demo data:
 *
 *     php artisan db:seed --class=NotificationsDemoSeeder
 *
 *  - A CUSTOMER gets five of each of: reservation.created, reservation.confirmed, reservation.cancelled, reservation.expired, payment.failed, payment.refunded,
 *    and broadcast (35 in all).
 *  - A PROVIDER gets five of each of: provider.booking.confirmed, provider.booking.cancelled, provider.document.approved, provider.document.rejected,
 *    provider.document.update_requested, activity.suspended, activity.suspension_lifted, provider.profile_change.approved, provider.profile_change.rejected,
 *    provider.withdrawal.processing, provider.withdrawal.paid, provider.withdrawal.rejected, provider.withdrawal.failed, provider.verified, provider.rejected,
 *    and broadcast (80 in all).
 *
 * Every one is made by the app's OWN notification class, from a real record (the customer's own reservations and payments, the provider's own bookings,
 * activities, withdrawals and profile changes, each of the right status), so what is stored is exactly what the app stores: the same type, the same fields,
 * the same sentence. Where the person has no record of the right kind (a customer who never had a booking expire, say), the notification is made from a
 * record that is not saved: it has the same fields, with no id to open. Each is dated over the last 30 days, and about nine in ten of the older ones are
 * read, a quarter of the recent ones; nothing is dated in the future.
 *
 * Every notification has an id of its own that comes from the person, the kind and the number, so it is the same on every run, and running it again adds
 * nothing. Admin notifications are not made (they go to staff, not to customers or providers). It is refused in production, like the other demo seeders.
 *
 * Volume at scale 1 (the demo data): 23 customers and 23 providers, so 805 + 1,840 = about 2,650 notifications.
 * Prerequisites: DatabaseSeeder. Reservations, payments and withdrawals make them more real: run ProviderAnalyticsDemoSeeder and ProviderWithdrawalsDemoSeeder first.
 */
class NotificationsDemoSeeder extends BulkSeeder
{
    private const PER_KIND = 5;

    private const DAYS = 30;

    private const BROADCASTS = [
        ['Summer season is here', 'Book your next trip now: new activities were added this week.'], ['Maintenance tonight', 'The app will be unavailable for a few minutes after midnight.'],
        ['New feature: favourites', 'Save the activities you like and find them again in one tap.'], ['موسم الصيف بدأ', 'احجز رحلتك القادمة الآن، أضفنا أنشطة جديدة هذا الأسبوع.'],
        ['تحديث جديد للتطبيق', 'حدّث التطبيق للحصول على أحدث المزايا.'],
    ];

    /** The kinds a customer gets, by the type the app shows. */
    private const CUSTOMER_KINDS = ['reservation.created', 'reservation.confirmed', 'reservation.cancelled', 'reservation.expired', 'payment.failed', 'payment.refunded', 'broadcast'];

    /** The kinds a provider gets. */
    private const PROVIDER_KINDS = ['provider.booking.confirmed', 'provider.booking.cancelled', 'provider.document.approved', 'provider.document.rejected', 'provider.document.update_requested',
        'activity.suspended', 'activity.suspension_lifted', 'provider.profile_change.approved', 'provider.profile_change.rejected', 'provider.withdrawal.processing', 'provider.withdrawal.paid',
        'provider.withdrawal.rejected', 'provider.withdrawal.failed', 'provider.verified', 'provider.rejected', 'broadcast'];

    private const DOCUMENTS = ['Commercial registration', 'Tourism licence', 'National ID', 'Marine safety certificate'];

    private const NOTES = ['The photo is not clear.', 'The document has expired.', 'The name does not match the business name.'];

    private int $notified = 0;

    protected function seed(): void
    {
        $this->needs('users', 'DatabaseSeeder');

        $customers = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->whereNull('users.deleted_at')->where('users.status', 'active')->orderBy('customers.id')->get(['users.id as user_id', 'customers.id as customer_id']);
        $providers = DB::table('providers')->join('users', 'users.id', '=', 'providers.user_id')->whereNull('users.deleted_at')->where('users.status', 'active')->whereNull('providers.deleted_at')
            ->orderBy('providers.id')->get(['users.id as user_id', 'providers.id as provider_id']);

        foreach ($customers as $c) {
            $this->forCustomer(User::find($c->user_id), (int) $c->customer_id);
        }
        foreach ($providers as $p) {
            $this->forProvider(User::find($p->user_id), Provider::find($p->provider_id));
        }

        $this->line(sprintf('%d customers and %d providers: %d new notifications.', count($customers), count($providers), $this->notified));
    }

    // --- A customer ---------------------------------------------------------------------------------------------------

    private function forCustomer(User $user, int $customerId): void
    {
        $reservations = Reservation::with('activity')->where('customer_id', $customerId)->orderBy('id')->get();
        $payments = Payment::whereIn('reservation_id', $reservations->pluck('id'))->orderBy('id')->get();
        $any = $reservations->first();
        $activity = Activity::where('status', 'published')->orderBy('id')->first();

        // Only a record of the RIGHT status will do: a "cancelled" notification must not open a booking that is confirmed. With none, an unsaved one is used.
        $of = fn (string $status) => $reservations->where('status', $status)->values();
        $pay = fn (string $status) => $payments->where('status', $status)->values();

        $this->kinds($user, self::CUSTOMER_KINDS, fn (string $kind, int $n) => match ($kind) {
            'reservation.created' => new ReservationCreatedNotification($this->pick5($of('pending'), $n) ?? $this->reservation($activity, 'pending', $n)),
            'reservation.confirmed' => new ReservationConfirmedNotification($this->pick5($of('confirmed'), $n) ?? $this->reservation($activity, 'confirmed', $n)),
            'reservation.cancelled' => new ReservationCancelledNotification($this->pick5($of('cancelled'), $n) ?? $this->reservation($activity, 'cancelled', $n)),
            'reservation.expired' => new ReservationExpiredNotification($this->pick5($of('expired'), $n) ?? $this->reservation($activity, 'expired', $n)),
            'payment.failed' => new PaymentFailedNotification($this->pick5($pay('failed'), $n) ?? $this->payment($any, 'failed', $n)),
            'payment.refunded' => new PaymentRefundedNotification($this->pick5($pay('refunded'), $n) ?? $this->payment($any, 'refunded', $n)),
            'broadcast' => $this->broadcast($n),
        });
    }

    // --- A provider ---------------------------------------------------------------------------------------------------

    private function forProvider(User $user, Provider $provider): void
    {
        $bookings = Reservation::with('activity')->whereIn('activity_id', Activity::where('provider_id', $provider->id)->pluck('id'))->orderBy('id')->get();
        $activities = Activity::where('provider_id', $provider->id)->orderBy('id')->get();
        $withdrawals = Withdrawal::where('provider_id', $provider->id)->orderBy('id')->get();
        $changes = ProviderProfileChange::where('provider_id', $provider->id)->orderBy('id')->get();

        $kept = $bookings->whereIn('status', ['confirmed', 'completed'])->values();
        $cancelled = $bookings->where('status', 'cancelled')->values();
        $change = fn (string $status) => $changes->where('status', $status)->values();
        $withdrawal = fn (string $status) => $withdrawals->where('status', $status)->values();
        $anyActivity = $activities->first() ?? Activity::orderBy('id')->first();

        $this->kinds($user, self::PROVIDER_KINDS, fn (string $kind, int $n) => match ($kind) {
            'provider.booking.confirmed' => new NewBookingProviderNotification($this->pick5($kept, $n) ?? $this->reservation($anyActivity, 'confirmed', $n)),
            'provider.booking.cancelled' => new BookingCancelledProviderNotification($this->pick5($cancelled, $n) ?? $this->reservation($anyActivity, 'cancelled', $n)),
            'provider.document.approved' => new ProviderDocumentReviewedNotification($provider, ProviderDocumentEvent::APPROVED, self::DOCUMENTS[($n - 1) % count(self::DOCUMENTS)]),
            'provider.document.rejected' => new ProviderDocumentReviewedNotification($provider, ProviderDocumentEvent::REJECTED, self::DOCUMENTS[($n - 1) % count(self::DOCUMENTS)], self::NOTES[($n - 1) % count(self::NOTES)]),
            'provider.document.update_requested' => new ProviderDocumentReviewedNotification($provider, ProviderDocumentEvent::UPDATE_REQUESTED, self::DOCUMENTS[($n - 1) % count(self::DOCUMENTS)], self::NOTES[($n - 1) % count(self::NOTES)]),
            'activity.suspended' => new ActivitySuspendedNotification($this->pick5($activities, $n) ?? $anyActivity),
            'activity.suspension_lifted' => new ActivitySuspensionLiftedNotification($this->pick5($activities, $n) ?? $anyActivity),
            'provider.profile_change.approved' => new ProviderProfileChangeReviewedNotification($this->pick5($change('approved'), $n) ?? $this->profileChange($provider, 'approved', $n)),
            'provider.profile_change.rejected' => new ProviderProfileChangeReviewedNotification($this->pick5($change('rejected'), $n) ?? $this->profileChange($provider, 'rejected', $n)),
            'provider.withdrawal.processing' => new WithdrawalStatusNotification($this->pick5($withdrawal('processing'), $n) ?? $this->withdrawal($provider, 'processing', $n)),
            'provider.withdrawal.paid' => new WithdrawalStatusNotification($this->pick5($withdrawal('paid'), $n) ?? $this->withdrawal($provider, 'paid', $n)),
            'provider.withdrawal.rejected' => new WithdrawalStatusNotification($this->pick5($withdrawal('rejected'), $n) ?? $this->withdrawal($provider, 'rejected', $n)),
            'provider.withdrawal.failed' => new WithdrawalStatusNotification($this->pick5($withdrawal('failed'), $n) ?? $this->withdrawal($provider, 'failed', $n)),
            'provider.verified' => new ProviderVerifiedNotification($provider),
            'provider.rejected' => new ProviderRejectedNotification($provider),
            'broadcast' => $this->broadcast($n),
        });
    }

    // --- The rows -------------------------------------------------------------------------------------------------------

    /**
     * Five notifications of each kind for one person, each made by `$make` (the app's own class).
     *
     * @param  list<string>  $kinds
     * @param  callable(string, int): Notification  $make
     */
    private function kinds(User $user, array $kinds, callable $make): void
    {
        $rows = [];

        foreach ($kinds as $kind) {
            foreach (range(1, self::PER_KIND) as $n) {
                $notification = $make($kind, $n);
                $id = $this->uuid('notification-demo', "{$user->id}-{$kind}-{$n}");

                // Dated and read, from a stream of its own: the same whenever it is made.
                $this->startRandom((crc32("notification-demo|{$id}") ^ (int) config('seeding.seed')) & 0x7FFFFFFF);
                $at = $this->now->copy()->subMinutes($this->rand(5, self::DAYS * 1440));
                $read = $at->lessThan($this->now->copy()->subDays(10)) ? $this->chance(88) : $this->chance(25);

                $rows[] = ['id' => $id, 'type' => $notification::class, 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => json_encode($notification->toArray($user)),
                    'read_at' => $read ? $this->ts(min($at->copy()->addMinutes($this->rand(5, 720)), $this->now->copy())) : null, 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at)];
            }
        }

        $this->notified += $this->insert('notifications', $rows);
    }

    /** The $n-th of a person's records of one kind, going round when there are fewer than five; null when there are none. */
    private function pick5(Collection $records, int $n): mixed
    {
        return $records->isEmpty() ? null : $records[($n - 1) % $records->count()];
    }

    // --- Records that are not saved: the same fields, no id to open --------------------------------------------------------------

    private function reservation(?Activity $activity, string $status, int $n): Reservation
    {
        $trip = $this->now->copy()->addDays($n * 3)->toDateString();
        $reservation = (new Reservation)->forceFill(['uuid' => $this->uuid('notification-demo-reservation', "{$status}-{$n}"), 'status' => $status, 'total' => 400 + $n * 50, 'currency' => config('platform.currency'),
            'reservation_date' => $trip, 'participants' => ['adult' => $n], 'quantity' => null, 'cancellation_source' => $status === 'cancelled' ? CancellationSource::Customer : null]);

        return $reservation->setRelation('activity', $activity ?? new Activity(['title' => 'Your activity']));
    }

    private function payment(?Reservation $reservation, string $status, int $n): Payment
    {
        return (new Payment)->forceFill(['uuid' => $this->uuid('notification-demo-payment', "{$status}-{$n}"), 'reference_id' => 'PAY-DEMO'.$n, 'amount' => 400 + $n * 50, 'refunded_amount' => $status === 'refunded' ? 400 + $n * 50 : 0,
            'currency' => config('platform.currency'), 'status' => $status]);
    }

    private function profileChange(Provider $provider, string $status, int $n): ProviderProfileChange
    {
        return (new ProviderProfileChange)->forceFill(['uuid' => $this->uuid('notification-demo-change', "{$provider->id}-{$status}-{$n}"), 'status' => $status, 'requested_changes' => ['business_name' => 'New name'],
            'rejection_reason' => $status === 'rejected' ? 'The name does not match your commercial registration.' : null]);
    }

    private function withdrawal(Provider $provider, string $status, int $n): Withdrawal
    {
        return (new Withdrawal)->forceFill(['uuid' => $this->uuid('notification-demo-withdrawal', "{$provider->id}-{$status}-{$n}"), 'provider_id' => $provider->id, 'amount' => 500 + $n * 100, 'fee' => 0,
            'net_amount' => 500 + $n * 100, 'currency' => config('platform.currency'), 'status' => $status, 'payout_reference' => $status === 'paid' ? 'TRF-DEMO'.$n : null,
            'status_reason' => in_array($status, ['rejected', 'failed'], true) ? 'The bank returned the transfer.' : null]);
    }

    private function broadcast(int $n): BroadcastNotification
    {
        [$title, $body] = self::BROADCASTS[($n - 1) % count(self::BROADCASTS)];

        return new BroadcastNotification($title, $body, $this->uuid('notification-demo-broadcast', (string) $n));
    }
}
