<?php

namespace App\Services\CustomerApp;

use App\Models\Reservation;
use App\Models\Review;
use Carbon\Carbon;

/**
 * A booking as the customer app shows it. The platform's statuses are pending, confirmed, cancelled, expired and completed; the customer sees:
 *
 *   pending_payment  a pending booking whose hold has not run out (it waits for the payment to be confirmed)
 *   upcoming         a confirmed booking (it stays "upcoming" until it is completed)
 *   completed, cancelled, expired   as they are; a pending booking whose hold ran out is "expired" even before the system marks it so
 *
 * `can_cancel` and `can_review` are decided here, by the backend.
 */
class BookingPresenter
{
    public const FILTERS = ['upcoming', 'completed', 'cancelled', 'pending_payment', 'expired'];

    public function __construct(private readonly ActivityCatalog $catalog) {}

    public function reference(Reservation $r): string
    {
        return 'BH-'.$r->reservation_date->format('ymd').'-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT);
    }

    /** When the trip starts (the departure's time, in the platform's timezone), as a moment. */
    public function startsAt(Reservation $r): Carbon
    {
        $time = substr((string) ($r->schedulePeriod?->start_time ?? '00:00'), 0, 5);

        return Carbon::parse($r->reservation_date->toDateString().' '.$time, Checkout::tz());
    }

    public function status(Reservation $r): string
    {
        return match ($r->status->value) {
            'pending' => $r->hold_expires_at !== null && $r->hold_expires_at->isPast() ? 'expired' : 'pending_payment',
            'confirmed' => 'upcoming',
            default => $r->status->value,
        };
    }

    public function canCancel(Reservation $r): bool
    {
        return in_array($this->status($r), ['pending_payment', 'upcoming'], true) && ($r->status->value === 'pending' || $this->startsAt($r)->gt(now()));
    }

    public function canReview(Reservation $r, bool $alreadyReviewed): bool
    {
        return $r->status->value === 'completed' && ! $alreadyReviewed;
    }

    /** @return array<string, mixed> the card of the booking's activity: its price is what was paid for a guest, before any coupon */
    private function activity(Reservation $r, array $favorites): array
    {
        $a = $r->activity;
        $a->setAttribute('min_price', round((float) $r->subtotal / max(1, (int) array_sum((array) $r->participants)), 2));

        return $this->catalog->card($a, $favorites);
    }

    /** @return array<string, mixed> */
    public function summary(Reservation $r, array $favorites, array $reviewedActivityIds): array
    {
        return [
            'id' => $r->id,
            'reference' => $this->reference($r),
            'status' => $this->status($r),
            'date_time' => $this->startsAt($r)->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'guests' => (int) array_sum((array) $r->participants),
            'total' => (float) $r->total,
            'can_cancel' => $this->canCancel($r),
            'can_review' => $this->canReview($r, in_array($r->activity_id, $reviewedActivityIds, true)),
            'activity' => $this->activity($r, $favorites),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Reservation $r, array $favorites, array $reviewedActivityIds): array
    {
        $payment = $r->payments->sortByDesc('id')->first();
        $provider = $r->activity->provider;
        $shown = in_array($r->status->value, ['confirmed', 'completed'], true);

        return $this->summary($r, $favorites, $reviewedActivityIds) + [
            'payment_method' => $r->payment_method,
            'payment_status' => $payment?->status?->value,
            'subtotal' => (float) $r->subtotal,
            'discount' => (float) $r->discount,
            'service_fee' => (float) $r->service_fee,
            'vat' => (float) $r->tax,
            'refund_amount' => round((float) $r->payments->sum('refunded_amount'), 2),
            'coupon' => $r->couponRedemption ? ['code' => $r->couponRedemption->code, 'discount' => (float) $r->couponRedemption->discount_amount] : null,
            'cancellation_reason' => $r->cancellation_reason,
            'meeting_point' => ['name' => $r->activity->meeting_point, 'note' => null, 'lat' => $r->activity->lat !== null ? (float) $r->activity->lat : null, 'lng' => $r->activity->lng !== null ? (float) $r->activity->lng : null],
            // The provider's phone is shown once the booking is confirmed (and after), not to someone who has not paid.
            'host' => ['id' => $provider->id, 'name' => $provider->business_name, 'avatar' => $this->catalog->url($provider->logo), 'phone' => $shown ? $provider->contact_phone : null],
        ];
    }

    /** @return list<int> the activities this customer has already reviewed, among these bookings */
    public function reviewedActivityIds(int $customerId, iterable $reservations): array
    {
        return Review::where('customer_id', $customerId)->whereIn('activity_id', collect($reservations)->pluck('activity_id'))->pluck('activity_id')->all();
    }
}
