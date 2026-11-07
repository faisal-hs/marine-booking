<?php

namespace App\Services;

use App\Notifications\Activities\ActivityApprovedNotification;
use App\Notifications\Activities\ActivityPublishedNotification;
use App\Notifications\Activities\ActivityRejectedNotification;
use App\Notifications\Activities\ActivitySentBackToReviewNotification;
use App\Notifications\Payments\PaymentFailedNotification;
use App\Notifications\Payments\PaymentRefundedNotification;
use App\Notifications\Reservations\Provider\BookingCancelledProviderNotification;
use App\Notifications\Reservations\Provider\NewBookingProviderNotification;
use App\Notifications\Reservations\ReservationCancelledNotification;
use App\Notifications\Reservations\ReservationConfirmedNotification;
use App\Notifications\Reservations\ReservationCreatedNotification;
use App\Notifications\Reservations\ReservationExpiredNotification;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One person can have a customer account AND a provider account, but notifications are stored per
 * user. Without this, the customer app would list "New booking received" and the provider app would
 * list "Your reservation is confirmed". Each account's feed leaves out the other account's
 * notifications.
 *
 * It subtracts the OTHER account's classes rather than listing its own, so a notification that is
 * not classified here (a new class someone forgot) shows in both feeds instead of vanishing. A test
 * fails if a concrete notification class is not classified, so that cannot go unnoticed.
 *
 * Shown in BOTH feeds, on purpose: announcements, and the outcome of a provider request (a customer
 * who asked to become a provider must see the answer while they are still on the customer side).
 */
class NotificationFeed
{
    /** Notifications that only make sense to a customer. */
    public const CUSTOMER_ONLY = [
        ReservationCreatedNotification::class,
        ReservationConfirmedNotification::class,
        ReservationCancelledNotification::class,
        ReservationExpiredNotification::class,
        PaymentFailedNotification::class,
        PaymentRefundedNotification::class,
    ];

    /** Notifications that only make sense to a provider. */
    public const PROVIDER_ONLY = [
        NewBookingProviderNotification::class,
        BookingCancelledProviderNotification::class,
        ActivityApprovedNotification::class,
        ActivityPublishedNotification::class,
        ActivityRejectedNotification::class,
        ActivitySentBackToReviewNotification::class,
        \App\Notifications\Providers\ProviderProfileChangeReviewedNotification::class,
        \App\Notifications\Wallet\WithdrawalStatusNotification::class,
    ];

    /**
     * @template T of EloquentBuilder|QueryBuilder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param  T  $query  a notifications query
     * @return T
     */
    public function scope($query, string $account)
    {
        $other = $account === AccountService::PROVIDER ? self::CUSTOMER_ONLY : self::PROVIDER_ONLY;

        return $query->whereNotIn('type', $other);
    }
}
