<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\BookingSummaryRequest;
use App\Http\Requests\CustomerApp\CancelBookingRequest;
use App\Http\Requests\CustomerApp\CreateBookingRequest;
use App\Http\Requests\CustomerApp\ListBookingsRequest;
use App\Models\Customer;
use App\Models\Reservation;
use App\Services\ActivityViewTracker;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\CustomerApp\BookingCancellation;
use App\Services\CustomerApp\BookingPresenter;
use App\Services\CustomerApp\Checkout;
use App\Services\PaymentService;
use App\Services\ReservationService;
use App\Support\ProviderApi\ErrorCode;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pricing, booking and paying, and the customer's trips. A booking is confirmed only when its payment is confirmed (by the gateway, not by the app).
 * Every booking is looked up among the signed-in customer's own, so another customer's is "not found".
 */
class BookingController extends CustomerAppController
{
    private const WITH = ['activity.provider', 'activity.city', 'activity.coverImage', 'schedulePeriod', 'payments', 'couponRedemption'];

    public function __construct(
        private readonly ActivityCatalog $catalog,
        private readonly Checkout $checkout,
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
        private readonly BookingPresenter $presenter,
        private readonly BookingCancellation $cancellation,
    ) {}

    public function summary(BookingSummaryRequest $request)
    {
        $activity = $this->catalog->find($request->integer('activity_id')) ?? throw ProviderApiException::notFound('Activity not found.');

        return $this->ok($this->checkout->summary($activity, $request->input('date'), $request->input('time'), $request->integer('guests'), $request->input('coupon_code')));
    }

    /**
     * Books, and starts the payment. The `Idempotency-Key` header is required: sending the same key again (a double tap, a retry after a dropped
     * connection) returns the booking the first request made and books nothing more.
     */
    public function store(CreateBookingRequest $request)
    {
        $customer = $this->customer($request);
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 80) {
            throw new ProviderApiException('Send an Idempotency-Key header (up to 80 characters) with a new value for each booking attempt.', 422, ErrorCode::IDEMPOTENCY_KEY_REQUIRED);
        }

        if ($existing = $this->byKey($customer, $key)) {
            return $this->ok($this->created_($existing), 'Booking created');
        }

        $activity = $this->catalog->find($request->integer('activity_id')) ?? throw ProviderApiException::notFound('Activity not found.');

        // Tabby and Tamara only where the provider accepts them for this activity.
        $method = $request->input('payment_method');
        if (in_array($method, CreateBookingRequest::PER_ACTIVITY_METHODS, true) && ! $activity->{'allows_'.$method}) {
            throw ValidationException::withMessages(['payment_method' => [ucfirst($method).' is not accepted for this activity.']]);
        }

        [$option, $period, $day] = $this->checkout->slot($activity, $request->input('date'), $request->input('time'));
        $this->checkout->assertGuests($activity, $option, $period, $day, $request->integer('guests'));

        try {
            $reservation = $this->reservations->create($customer, $activity, $option, $period, $day, ['adult' => $request->integer('guests')], null, null, $request->input('coupon_code'),
                ActivityViewTracker::source($request), [
                    'contact_name' => $request->input('contact_name'), 'contact_phone' => $request->input('contact_phone'), 'contact_email' => $request->input('contact_email'),
                    'payment_method' => $request->input('payment_method'), 'idempotency_key' => $key,
                ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key at the same moment: the second one gets the first one's booking.
            return $this->ok($this->created_($this->byKey($customer, $key)), 'Booking created');
        } catch (ValidationException $e) {
            // The departure filled up between the check and the booking: that is a conflict, not a mistake in the request.
            if (isset($e->errors()['participants']) || isset($e->errors()['quantity'])) {
                throw new ProviderApiException('This departure no longer has room for that many guests.', 409, ErrorCode::SLOT_UNAVAILABLE);
            }

            throw $e;
        }

        try {
            $paid = $this->payments->initiate($reservation, array_filter(['payment_method' => $request->input('payment_method'), 'simulate' => $request->input('simulate')]));
        } catch (PaymentGatewayUnavailableException) {
            // Nothing was charged and nothing can be, so the booking is let go at once (its place and any coupon use come back).
            $reservation->update(['status' => 'expired', 'hold_expires_at' => now()]);

            throw new ProviderApiException('Payment is not available right now. Nothing was booked or charged; please try again shortly.', 503, ErrorCode::PAYMENT_UNAVAILABLE);
        }

        return $this->created($this->created_($reservation->fresh(), $paid['redirect_url'] ?? null), 'Booking created');
    }

    public function show(Request $request, string $id)
    {
        $customer = $this->customer($request);
        $reservation = $this->own($customer, $id);

        return $this->ok($this->presenter->detail($reservation, $this->catalog->favoritesAmong($customer->id, [$reservation->activity]), $this->presenter->reviewedActivityIds($customer->id, [$reservation])));
    }

    public function index(ListBookingsRequest $request)
    {
        $customer = $this->customer($request);
        $query = $customer->reservations()->with(self::WITH);

        match ($request->input('status')) {
            'upcoming' => $query->where('status', 'confirmed')->orderBy('reservation_date')->orderBy('id'),
            'completed', 'cancelled' => $query->where('status', $request->input('status'))->orderByDesc('reservation_date')->orderByDesc('id'),
            'pending_payment' => $query->where('status', 'pending')->where('hold_expires_at', '>', now())->orderByDesc('id'),
            'expired' => $query->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($p) => $p->where('status', 'pending')->where('hold_expires_at', '<=', now())))->orderByDesc('id'),
            default => $query->orderByDesc('reservation_date')->orderByDesc('id'),
        };

        $page = $query->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));
        $favorites = $this->catalog->favoritesAmong($customer->id, collect($page->items())->pluck('activity'));
        $reviewed = $this->presenter->reviewedActivityIds($customer->id, $page->items());

        return $this->page($page, ['counts' => $this->counts($customer), 'bookings' => collect($page->items())->map(fn ($r) => $this->presenter->summary($r, $favorites, $reviewed))->all()]);
    }

    public function cancel(CancelBookingRequest $request, string $id)
    {
        $result = $this->cancellation->cancel($this->customer($request), $request->user(), $id, $request->validated('reason'));

        return $this->ok(['id' => $result['reservation']->id, 'status' => 'cancelled', 'refund_amount' => $result['refund_amount']], 'Booking cancelled');
    }

    private function own(Customer $customer, string $id): Reservation
    {
        return (ctype_digit($id) ? $customer->reservations()->with(self::WITH)->find((int) $id) : null) ?? throw ProviderApiException::notFound('Booking not found.');
    }

    private function byKey(Customer $customer, string $key): ?Reservation
    {
        return $customer->reservations()->where('idempotency_key', $key)->with(self::WITH)->first();
    }

    /** @return array<string, mixed> */
    private function created_(Reservation $r, ?string $redirectUrl = null): array
    {
        $r->loadMissing(self::WITH);
        $payment = $r->payments->sortByDesc('id')->first();

        return [
            'booking_id' => $r->id, 'reference' => $this->presenter->reference($r), 'status' => $this->presenter->status($r), 'total' => (float) $r->total,
            'payment_id' => $payment?->reference_id, 'payment_status' => $payment?->status?->value, 'redirect_url' => $redirectUrl,
        ];
    }

    /** @return array<string, int> over ALL the customer's bookings, whatever the filter */
    private function counts(Customer $customer): array
    {
        $r = $customer->reservations();

        return [
            'upcoming' => (clone $r)->where('status', 'confirmed')->count(),
            'completed' => (clone $r)->where('status', 'completed')->count(),
            'cancelled' => (clone $r)->where('status', 'cancelled')->count(),
            'pending_payment' => (clone $r)->where('status', 'pending')->where('hold_expires_at', '>', now())->count(),
            'expired' => (clone $r)->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($p) => $p->where('status', 'pending')->where('hold_expires_at', '<=', now())))->count(),
        ];
    }
}
