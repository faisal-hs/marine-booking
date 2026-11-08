<?php

namespace Database\Seeders\Bulk;

use App\Notifications\Activities\ActivitySuspendedNotification;
use App\Notifications\Providers\ProviderRejectedNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Notifications\Reservations\Admin\NewReservationAdminNotification;
use App\Notifications\Reservations\Provider\BookingCancelledProviderNotification;
use App\Notifications\Reservations\Provider\NewBookingProviderNotification;
use App\Notifications\Reservations\ReservationCancelledNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Notifications\Reservations\ReservationCreatedNotification;
use App\Notifications\Reservations\ReservationExpiredNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The in-app notification feed (Laravel's "notifications" table, which is not a model of its own), written from what
 * actually happened, with the app's own notification classes and payload keys:
 *  - for 60% of reservations, the customer's "created / confirmed / cancelled / expired" notice and, for paid ones,
 *    the provider's "new booking / booking cancelled" notice;
 *  - for 2% of them, an admin notice that links to the reservation (so the admin inbox links work);
 *  - publication and rejection notices for activities, and the outcome of every provider request.
 * 60% are marked read.
 *
 * Volume at scale 1: about 209,000. Broadcast notifications are NOT generated: a historical announcement to
 * 20,000 customers would add 20,000 rows each (see BulkBroadcastSeeder).
 * Prerequisites: BulkReservationSeeder, BulkProviderRequestSeeder.
 */
class BulkNotificationSeeder extends BulkSeeder
{
    private const USER = 'App\\Models\\User';

    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder');

        $staff = DB::table('users')->where('phone', 'like', self::STAFF_PHONE_PREFIX.'%')->pluck('id')->all();

        $this->insert('notifications', $this->reservationNotices($staff));
        $this->insert('notifications', $this->activityNotices());
        $this->insert('notifications', $this->providerDecisionNotices());
    }

    private function notice(string $key, string $class, int $userId, array $data, string|Carbon $at): array
    {
        $at = $at instanceof Carbon ? $at : Carbon::parse($at);
        $at = $at->greaterThan($this->now) ? $this->now->copy() : $at;
        $read = $this->chance(60) ? $at->copy()->addMinutes($this->rand(1, 2880)) : null;

        return [
            'id' => $this->uuid('notification', $key),
            'type' => $class,
            'notifiable_type' => self::USER,
            'notifiable_id' => $userId,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'read_at' => $read ? $this->ts($read->greaterThan($this->now) ? $this->now : $read) : null,
            'created_at' => $this->ts($at),
            'updated_at' => $this->ts($at),
        ];
    }

    private function reservationNotices(array $staff): \Generator
    {
        $last = 0;
        while (true) {
            $rows = DB::table('reservations as r')
                ->join('customers as c', 'c.id', '=', 'r.customer_id')
                ->join('activities as a', 'a.id', '=', 'r.activity_id')
                ->join('providers as p', 'p.id', '=', 'a.provider_id')
                ->where('r.id', '>', $last)->orderBy('r.id')->limit(2000)
                ->get(['r.id', 'r.uuid', 'r.status', 'r.total', 'r.currency', 'r.confirmed_at', 'r.cancelled_at', 'r.hold_expires_at', 'r.created_at',
                    'c.user_id as customer_user', 'p.user_id as provider_user', 'a.title']);

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $r) {
                if ($r->id % 5 >= 3) {
                    continue; // 60% of reservations
                }

                $data = fn (string $type, string $message) => ['type' => $type, 'reservation_id' => $r->id, 'reservation_uuid' => $r->uuid, 'activity_title' => $r->title,
                    'total' => (float) $r->total, 'currency' => $r->currency, 'message' => $message];

                if ($this->chance(30)) {
                    yield $this->notice("r{$r->id}-created", ReservationCreatedNotification::class, $r->customer_user, $data('reservation.created', "Your reservation for {$r->title} was created."), $r->created_at);
                }
                if ($r->confirmed_at !== null) {
                    yield $this->notice("r{$r->id}-confirmed", ReservationConfirmedNotification::class, $r->customer_user, $data('reservation.confirmed', "Your reservation for {$r->title} is confirmed."), $r->confirmed_at);
                    yield $this->notice("r{$r->id}-new-booking", NewBookingProviderNotification::class, $r->provider_user, $data('reservation.provider.new_booking', "New booking for {$r->title}."), $r->confirmed_at);
                }
                if ($r->status === 'cancelled' && $r->cancelled_at !== null) {
                    yield $this->notice("r{$r->id}-cancelled", ReservationCancelledNotification::class, $r->customer_user, $data('reservation.cancelled', "Your reservation for {$r->title} was cancelled."), $r->cancelled_at);
                    if ($r->confirmed_at !== null) {
                        yield $this->notice("r{$r->id}-booking-cancelled", BookingCancelledProviderNotification::class, $r->provider_user, $data('reservation.provider.cancelled', "A booking for {$r->title} was cancelled."), $r->cancelled_at);
                    }
                }
                if ($r->status === 'expired' && $r->hold_expires_at !== null) {
                    yield $this->notice("r{$r->id}-expired", ReservationExpiredNotification::class, $r->customer_user, $data('reservation.expired', "Your reservation for {$r->title} expired."), $r->hold_expires_at);
                }
                if ($staff && $r->id % 50 === 0) {
                    yield $this->notice("r{$r->id}-admin", NewReservationAdminNotification::class, $this->pick($staff), $data('admin.reservation.created', "New reservation for {$r->title}."), $r->created_at);
                }
            }

            $last = $rows->last()->id;
        }
    }

    private function activityNotices(): \Generator
    {
        $last = 0;
        while (true) {
            $rows = DB::table('activities as a')->join('providers as p', 'p.id', '=', 'a.provider_id')
                ->where('a.slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%')->whereNotNull('a.suspended_at')
                ->where('a.id', '>', $last)->orderBy('a.id')->limit(2000)
                ->get(['a.id', 'a.uuid', 'a.title', 'a.suspension_reason', 'a.suspended_at', 'p.user_id as provider_user']);

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $a) {
                yield $this->notice("a{$a->id}-suspended", ActivitySuspendedNotification::class, $a->provider_user,
                    ['type' => 'activity.suspended', 'activity_uuid' => $a->uuid, 'activity_title' => $a->title,
                        'message' => "Your activity \"{$a->title}\" was suspended by an admin. Reason: {$a->suspension_reason}"], $a->suspended_at);
            }

            $last = $rows->last()->id;
        }
    }

    private function providerDecisionNotices(): \Generator
    {
        $last = 0;
        while (true) {
            $rows = DB::table('provider_requests as q')->join('providers as p', 'p.id', '=', 'q.provider_id')
                ->whereIn('q.status', ['approved', 'rejected'])->where('q.id', '>', $last)->orderBy('q.id')->limit(2000)
                ->get(['q.id', 'q.user_id', 'q.status', 'q.rejection_reason', 'q.reviewed_at', 'p.id as provider_id', 'p.uuid as provider_uuid', 'p.business_name']);

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $q) {
                $base = ['provider_id' => $q->provider_id, 'provider_uuid' => $q->provider_uuid, 'business_name' => $q->business_name];

                yield $q->status === 'approved'
                    ? $this->notice("q{$q->id}", ProviderVerifiedNotification::class, $q->user_id, ['type' => 'provider.verified', 'message' => 'Your provider account has been verified.'] + $base, $q->reviewed_at)
                    : $this->notice("q{$q->id}", ProviderRejectedNotification::class, $q->user_id, ['type' => 'provider.rejected', 'message' => "Your provider verification was not approved. Reason: {$q->rejection_reason}", 'rejection_reason' => $q->rejection_reason] + $base, $q->reviewed_at);
            }

            $last = $rows->last()->id;
        }
    }
}
