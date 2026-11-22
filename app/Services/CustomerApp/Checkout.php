<?php

namespace App\Services\CustomerApp;

use App\Enums\BookingModel;
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
use App\Support\ReservationType;
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

    /**
     * The price option a booking is for: the one asked for (`booking_option_id`), which must be an active option of the activity, else the activity's default option.
     */
    public function option(Activity $activity, ?int $optionId = null): ActivityBookingOption
    {
        if ($optionId === null) {
            return $this->catalog->defaultOption($activity)
                ?? throw new ProviderApiException('This activity cannot be booked in the app.', 422, ErrorCode::ACTIVITY_NOT_BOOKABLE);
        }

        return $activity->bookingOptions()->where('is_active', true)->find($optionId)
            ?? throw ValidationException::withMessages(['booking_option_id' => ['This price option is not available for this activity.']]);
    }

    /**
     * The departure of a booking: the price option, the schedule period and the day. A departure is named by its time (`time`, "HH:MM") or by its id (`slot_id`, as the
     * availability lists it); with both, the id is the one used.
     *
     * @return array{0: ActivityBookingOption, 1: ActivitySchedulePeriod, 2: Carbon}
     */
    public function slot(Activity $activity, string $date, ?string $time, ?int $optionId = null, ?int $slotId = null): array
    {
        $option = $this->option($activity, $optionId);
        // A plain date, as the availability and booking code compare dates; the platform's timezone only decides whether the DEPARTURE has left.
        $day = Carbon::parse($date)->startOfDay();
        $periods = $this->schedules->activeForActivity($activity->id);
        $period = $slotId !== null
            ? $periods->first(fn ($p) => $p->id === $slotId && $p->day_of_week === $day->dayOfWeek)
            : $periods->first(fn ($p) => $p->day_of_week === $day->dayOfWeek && substr($p->start_time, 0, 5) === $time);
        $time = $period ? substr($period->start_time, 0, 5) : $time;

        // No such departure, a day the provider closed, or a departure that has already left are one answer.
        if (! $period || ! $this->availability->isBookable($activity->id, $period->id, $day) || Carbon::parse("{$date} {$time}", self::tz())->lte(now())) {
            throw new ProviderApiException('This date and time is not available.', 409, ErrorCode::SLOT_UNAVAILABLE);
        }

        return [$option, $period, $day];
    }

    /**
     * What the customer said about the booking, as the pricing and the reservation need it, by the option's reservation type:
     *   persons   `participants` {adult, child, infant}, or `guests` (that many adults)
     *   units     `quantity`
     *   time      `duration` (hours or days, as the option counts)
     *   fixed     nothing: `guests` / `participants` may be sent for the places the booking takes
     * What does not belong to the type is left out, not an error. Returns `guests`: the number of places taken (null when none were named).
     *
     * @param  array<string, mixed>  $in
     * @return array{participants: ?array<string, int>, quantity: ?int, duration: ?int, guests: ?int}
     */
    public function details(Activity $activity, ActivityBookingOption $option, ActivitySchedulePeriod $period, Carbon $day, array $in): array
    {
        $type = ReservationType::of($option->booking_model);
        $participants = [];

        foreach ((array) ($in['participants'] ?? []) as $kind => $count) {
            if ((int) $count > 0) {
                $participants[(string) $kind] = (int) $count;
            }
        }

        if ($participants === [] && ! empty($in['guests'])) {
            $participants = ['adult' => (int) $in['guests']];
        }

        $guests = $participants === [] ? null : array_sum($participants);

        if ($type === ReservationType::PERSONS) {
            if ($guests === null) {
                throw ValidationException::withMessages(['guests' => ['Send the number of guests (guests), or the guests by kind (participants).']]);
            }

            $this->assertGuests($activity, $option, $period, $day, $guests);

            return ['participants' => $participants, 'quantity' => null, 'duration' => null, 'guests' => $guests];
        }

        if ($type === ReservationType::UNITS) {
            $quantity = (int) ($in['quantity'] ?? 0);

            if ($quantity < 1) {
                throw ValidationException::withMessages(['quantity' => ['Send how many units (quantity), at least 1.']]);
            }

            if ($option->max_quantity !== null) {
                $left = max(0, $option->max_quantity - (int) $this->reservations->consumedCapacity($option->id, $period->id, $day->toDateString())['quantity']);

                if ($quantity <= $option->max_quantity && $quantity > $left) {
                    throw new ProviderApiException($left === 0 ? 'This departure is full.' : "Only {$left} unit(s) are left on this departure.", 409, ErrorCode::SLOT_UNAVAILABLE);
                }
            }

            return ['participants' => null, 'quantity' => $quantity, 'duration' => null, 'guests' => $guests];
        }

        if ($type === ReservationType::TIME) {
            $duration = (int) ($in['duration'] ?? 0);

            if ($duration < 1) {
                throw ValidationException::withMessages(['duration' => ['Send how long (duration), at least 1 '.($option->booking_model === BookingModel::PerDay ? 'day' : 'hour').'.']]);
            }

            return ['participants' => $guests === null ? null : $participants, 'quantity' => null, 'duration' => $duration, 'guests' => $guests];
        }

        // fixed: one price for the booking. The places it takes are checked when they are named.
        if ($guests !== null) {
            $this->assertGuests($activity, $option, $period, $day, $guests);
        }

        return ['participants' => $guests === null ? null : $participants, 'quantity' => null, 'duration' => null, 'guests' => $guests];
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
     * The price of a booking, before it is made: the departure (`date` and `time` or `slot_id`), the price option (`booking_option_id`, else the default one), what the booking is
     * made of (see details()), and a coupon (`coupon_code`). The answer keeps its original fields (`unit_price`, `guests`, `subtotal`, `discount`, `service_fee`, `vat`,
     * `total`, `coupon`) and adds what the booking is: `reservation_type`, `booking_option`, `slot`, `details` and the `lines` the subtotal is made of.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function summary(Activity $activity, array $in): array
    {
        [$option, $period, $day] = $this->slot($activity, (string) $in['date'], $in['time'] ?? null, isset($in['booking_option_id']) ? (int) $in['booking_option_id'] : null, isset($in['slot_id']) ? (int) $in['slot_id'] : null);
        $details = $this->details($activity, $option, $period, $day, $in);
        $quote = $this->pricing->quote($activity, $option, $period, $details['participants'], $details['quantity'], $details['duration']);
        $subtotal = (float) $quote['subtotal'];
        $couponCode = $in['coupon_code'] ?? null;
        $discount = 0.0;
        $coupon = null;

        if ($couponCode !== null && trim((string) $couponCode) !== '') {
            try {
                ['coupon' => $c, 'discount' => $discount] = $this->coupons->resolve($activity, (string) $couponCode, $subtotal);
                $coupon = ['code' => $c->code, 'is_valid' => true, 'message' => $this->appliedMessage($c, $discount)];
            } catch (ValidationException $e) {
                $coupon = ['code' => CouponService::normalize((string) $couponCode), 'is_valid' => false, 'message' => $e->errors()['coupon_code'][0] ?? 'This coupon cannot be used.'];
            }
        }

        $totals = CheckoutTotals::compute($subtotal, $discount);
        $lines = array_map(fn (array $line) => [
            'type' => $line['participant_type'] ?? $line['type'],
            'label' => ucfirst((string) ($line['participant_type'] ?? $line['type'])),
            'quantity' => $line['quantity'],
            'unit_price' => (float) $line['unit_price'],
            'total' => (float) $line['total'],
        ], $quote['breakdown']);
        $places = $details['guests'];

        return [
            'currency' => $quote['currency'],
            'reservation_type' => ReservationType::of($option->booking_model),
            'booking_option' => ['id' => $option->id, 'name' => $option->name, 'reservation_type' => ReservationType::of($option->booking_model), 'booking_model' => $option->booking_model->value],
            'slot' => ['id' => $period->id, 'date' => $day->toDateString(), 'time' => substr($period->start_time, 0, 5), 'end_time' => $period->end_time ? substr($period->end_time, 0, 5) : null],
            'details' => ['guests' => $places, 'participants' => $details['participants'], 'quantity' => $details['quantity'], 'duration' => $details['duration']],
            'lines' => $lines,
            // One person's price, as it always was, for a per-person booking; the unit price (of the unit, the hour, the day, the whole booking) for the rest.
            'unit_price' => $option->booking_model->usesParticipantPricing() ? round($subtotal / max(1, (int) $places), 2) : (float) ($lines[0]['unit_price'] ?? $subtotal),
            'guests' => $places,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'service_fee' => $totals['service_fee'],
            'vat' => $totals['vat'],
            'total' => $totals['total'],
            'coupon' => $coupon,
        ];
    }

    /** The message that says a coupon applies: its percentage, or the amount it takes off (the coupon's own value when no price is known yet). */
    public function appliedMessage(Coupon $c, ?float $discount): string
    {
        $value = rtrim(rtrim(number_format($c->type === Coupon::PERCENT ? $c->value : ($discount ?? $c->value), 2, '.', ''), '0'), '.');

        return __($c->type === Coupon::PERCENT ? 'customer_app.coupon.applied_percent' : 'customer_app.coupon.applied_fixed', ['value' => $value, 'currency' => config('platform.currency')]);
    }

    /**
     * What is left of an option on a departure, in what the option is counted in: places for persons, time and fixed, units for units. null where there is no limit.
     */
    public function placesLeft(Activity $activity, ActivityBookingOption $option, ActivitySchedulePeriod $period, Carbon $day): ?int
    {
        if (ReservationType::of($option->booking_model) !== ReservationType::UNITS) {
            return $this->spotsLeft($activity, $option, $period, $day);
        }

        return $option->max_quantity === null ? null : max(0, $option->max_quantity - (int) $this->reservations->consumedCapacity($option->id, $period->id, $day->toDateString())['quantity']);
    }

    /**
     * The days from `$from` to `$to`, EVERY day of them, with the departures still to come and what is left on each. A day with none (past, closed by the provider, or full) is
     * listed all the same, with `is_available` false and no slots, so a calendar can draw the whole month. A slot has its `id` (what a booking names it by), its `time` and
     * `end_time`, `spots_left` (null where there is no limit) and `is_available`. The places are those of the price option asked for (`$optionId`), else the default one.
     *
     * @return array<string, mixed>
     */
    public function availability(Activity $activity, Carbon $from, Carbon $to, ?int $optionId = null): array
    {
        $option = $optionId !== null ? $this->option($activity, $optionId) : $this->catalog->defaultOption($activity);
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
                $left = $option ? $this->placesLeft($activity, $option, $period, $day) : 0;
                $list[] = [
                    'id' => $period->id,
                    'time' => $slot['start'],
                    'end_time' => $period->end_time ? substr($period->end_time, 0, 5) : null,
                    'spots_left' => $left,
                    'is_available' => $left === null || $left > 0,
                ];
            }

            usort($list, fn ($a, $b) => strcmp($a['time'], $b['time']));
            $days[] = ['date' => $date, 'is_available' => (bool) array_filter($list, fn ($s) => $s['is_available']), 'slots' => $list];
        }

        return [
            'timezone' => self::tz(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'booking_option' => $option ? ['id' => $option->id, 'name' => $option->name, 'reservation_type' => ReservationType::of($option->booking_model)] : null,
            'max_guests' => $option ? $this->capacity($activity, $option) : $activity->max_participants,
            'days' => $days,
        ];
    }
}
