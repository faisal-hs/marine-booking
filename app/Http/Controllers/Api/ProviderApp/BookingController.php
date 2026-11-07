<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\ListBookingsRequest;
use App\Services\ProviderApp\ProviderBookings;

class BookingController extends ProviderAppController
{
    public function __construct(private readonly ProviderBookings $bookings) {}

    public function index(ListBookingsRequest $request)
    {
        $provider = $this->provider($request);
        $page = $this->bookings->paginate($provider, $request->only(['filter', 'status', 'from', 'to']), $request->perPage(), $request->page());

        return $this->page($page, [
            'counts' => $this->bookings->counts($provider),
            'bookings' => collect($page->items())->map(fn ($row) => $this->bookings->format($row))->all(),
        ]);
    }
}
