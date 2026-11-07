<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Http\Requests\ProviderApp\CalendarRequest;
use App\Services\ProviderApp\ProviderCalendar;

class CalendarController extends ProviderAppController
{
    public function __construct(private readonly ProviderCalendar $calendar) {}

    public function show(CalendarRequest $request)
    {
        return $this->ok($this->calendar->month($this->provider($request), $request->validated('month')));
    }
}
