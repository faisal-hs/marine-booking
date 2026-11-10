<?php

namespace App\Services\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\ActivitySchedulePeriod;
use App\Models\Coupon;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\ScheduleRepositoryInterface;
use App\Services\ActivityPricingService;
use App\Services\AvailabilityService;
use App\Services\CouponService;
use App\Support\Money\CheckoutTotals;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The customer app's view of choosing a departure and pricing it. A booking is for the activity's default per-person option and one of its schedule
 * periods (the `time` is the period's start, in the platform's timezone). Capacity is the option's (else the activity's) maximum, less what confirmed
 * bookings and unexpired holds already take: the same count the booking itself uses.
 */
class Checkout
{
    public function __construct(
        private readonly ActivityCatalog $catalog,
        private readonly ActivityPricingService $pricing,
        private readonly CouponService $coupons,
        private readonly AvailabilityService $availability,
        private readonly ScheduleRepositoryInterface $schedules,
        private readonly ReservationRepositoryInterface $reservations,
    ) {}

    public static function tz(): string
    {
        return (string) config('platform.timezone');
    }

    /** @return array{0: ActivityBookingOption, 1: ActivitySchedulePeriod, 2: Carbon} the option, the period, and the date as a day in the platform's timezone */
    public function slot(Activity $activity, string $date, string $time): array
    {
        $option = $this->catalog->defaultOption($activity)
            ?? throw new ProviderApiException('This activity cannot be booked in the app.', 422, ErrorCode::ACTIVITY_NOT_BOOKABLE);

        // A plain date, as the availability and booking code compare dates; the platform's timezone only decides whether the DEPARTURE has left.
        $day = Carbon::parse($date)->startOfDay();
        $period = $this->schedules->activeForActivity($activity->id)->first(fn ($p) => $p->day_of_week === $day->dayOfWeek && substr($p->start_time, 0, 5) === $time);

        // No such departure, a day the provider closed, or a departure that has already left are one answer.
        if (! $period || ! $this->availability->isBookable($activity->id, $period->id, $day) || Carbon::parse("{$date} {$time}", self::tz())->lte(now())) {
            throw new ProviderApiException('This date and time is not available.', 409, ErrorCode::SLOT_UNAVAILABLE);
        }

        return [$option, $period, $day];
    }

    /** The most guests a departure takes: the option's maximum, else the activity's, else no limit (null). */
    public function capacity(Activity $activity, ActivityBookingOption $option): ?int
    {
        return $option->max_participants ?? $activity->max_participants;
    }

    /** Places left on a departure (null: no limit). */
    public function spotsLeft(Activity $activity, ActivityBookingOption $option, ActivitySchedulePeriod $period, Carbon $day): ?int
    {
        $capacity = $this->capacity($activity, $option);

        return $capacity === null ? null : max(0, $capacity - (int) $this->reservations->consumedCapacity($option->id, $period->id, $day->toDateString())['participants']);
    }

    /** Refuses a number of guests the option does not take, or that the departure has no room for. */
    public function assertGuests(Activity $activity, ActivityBookingOption $option, ActivitySchedulePeriod $period, Carbon $day, int $guests): void
    {
        $min = max(1, (int) $option->min_participants);
        $max = $this->capacity($activity, $option);

        if ($guests < $min || ($max !== null && $guests > $max)) {
            throw ValidationException::withMessages(['guests' => [$max !== null ? "Guests must be between {$min} and {$max}." : "At least {$min} guest(s) are needed."]]);
        }

        $left = $this->spotsLeft($activity, $option, $period, $day);
        if ($left !== null && $guests > $left) {
            throw new ProviderApiException($left === 0 ? 'This departure is full.' : "Only {$left} place(s) are left on this departure.", 409, ErrorCode::SLOT_UNAVAILABLE);
        }
    }

    /**
     * What a booking would cost, without booking anything or using up a coupon. A coupon that cannot be used does not fail the summary: it is reported
     * (`is_valid: false` and why) and the summary is worked out without it, so the app can show the reason beside the box.
     *
     * @return array<string, mixed>
     */
    public function summary(Activity $activity, string $date, string $time, int $guests, ?string $couponCode): array
    {
        [$option, $period, $day] = $this->slot($activity, $date, $time);
        $this->assertGuests($activity, $option, $period, $day, $guests);

        $quote = $this->pricing->quote($activity, $option, $period, ['adult' => $guests]);
        $subtotal = (float) $quote['subtotal'];

        $discount = 0.0;
        $coupon = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            try {
                ['coupon' => $c, 'discount' => $discount] = $this->coupons->resolve($activity, $couponCode, $subtotal);
                $coupon = ['code' => $c->code, 'is_valid' => true, 'message' => $this->appliedMessage($c, $discount)];
            } catch (ValidationException $e) {
                $coupon = ['code' => CouponService::normalize($couponCode), 'is_valid' => false, 'message' => $e->errors()['coupon_code'][0] ?? 'This coupon cannot be used.'];
            }
        }

        $totals = CheckoutTotals::compute($subtotal, $discount);

        return [
            'currency' => $quote['currency'],
            'unit_price' => round($subtotal / $guests, 2),
            'guests' => $guests,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'service_fee' => $totals['service_fee'],
            'vat' => $totals['vat'],
            'total' => $totals['total'],
            'coupon' => $coupon,
        ];
    }

    private function appliedMessage(Coupon $c, float $discount): string
    {
        $value = rtrim(rtrim(number_format($c->type === Coupon::PERCENT ? $c->value : $discount, 2, '.', ''), '0'), '.');

        return __($c->type === Coupon::PERCENT ? 'customer_app.coupon.applied_percent' : 'customer_app.coupon.applied_fixed', ['value' => $value, 'currency' => config('platform.currency')]);
    }

    /**
     * The days of a window with their departures and the places left on each. Only departures still to come are listed; a day with none is not
     * available. `spots_left` is null where there is no limit.
     *
     * @return array<string, mixed>
     */
    public function availability(Activity $activity, Carbon $from, Carbon $to): array
    {
        $option = $this->catalog->defaultOption($activity);
        $periods = $this->schedules->activeForActivity($activity->id);
        $resolved = $this->availability->resolveForRange($activity->id, $from, $to);
        $days = [];

        foreach ($resolved as $date => $slots) {
            $day = Carbon::parse($date)->startOfDay();
            $list = [];

            foreach ($slots as $slot) {
                $period = $periods->first(fn ($p) => $p->day_of_week === $day->dayOfWeek && substr($p->start_time, 0, 5) === $slot['start']);
                if (! $period || Carbon::parse("{$date} {$slot['start']}", self::tz())->lte(now())) {
                    continue;
                }
                $list[] = ['time' => $slot['start'], 'spots_left' => $option ? $this->spotsLeft($activity, $option, $period, $day) : 0];
            }

            usort($list, fn ($a, $b) => strcmp($a['time'], $b['time']));
            $days[] = ['date' => $date, 'is_available' => (bool) array_filter($list, fn ($s) => $s['spots_left'] === null || $s['spots_left'] > 0), 'slots' => $list];
        }

        return ['timezone' => self::tz(), 'max_guests' => $option ? $this->capacity($activity, $option) : $activity->max_participants, 'days' => $days];
    }
}
