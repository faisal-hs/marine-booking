<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\CancelBookingRequest;
use App\Http\Requests\ProviderApp\ListBookingsRequest;
use App\Services\ProviderApp\ProviderBookingCancellation;
use App\Services\ProviderApp\ProviderBookings;

class BookingController extends ProviderAppController
{
    public function __construct(private readonly ProviderBookings $bookings, private readonly ProviderBookingCancellation $cancellation) {}

    public function index(ListBookingsRequest $request)
    {
        $provider = $this->provider($request);
        $page = $this->bookings->paginate($provider, $request->only(['filter', 'status', 'from', 'to', 'date']), $request->perPage(), $request->page());

        return $this->page($page, [
            'counts' => $this->bookings->counts($provider),
            'bookings' => collect($page->items())->map(fn ($row) => $this->bookings->format($row))->all(),
        ]);
    }

    /**
     * Cancel a CONFIRMED booking, with a reason. Bookings confirm themselves when paid, so there is no accept or decline.
     * The customer is refunded in full and told it was the provider who cancelled.
     */
    public function cancel(CancelBookingRequest $request, string $id)
    {
        $result = $this->cancellation->cancel($this->provider($request), $id, $request->validated('reason'), $request->user());
        $reservation = $result['reservation'];

        return $this->ok([
            'id' => $reservation->id,
            'status' => $reservation->status->value,
            'cancellation_source' => $reservation->cancellation_source->value,
            'cancellation_reason' => $reservation->cancellation_reason,
            'cancelled_at' => $reservation->cancelled_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'refunded_amount' => $result['refunded'],
        ], 'Booking cancelled');
    }
}
