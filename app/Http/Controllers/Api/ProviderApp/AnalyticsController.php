<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\AnalyticsRequest;
use App\Models\Activity;
use App\Services\ProviderApp\ProviderAnalytics;
use Carbon\Carbon;

class AnalyticsController extends ProviderAppController
{
    public function __construct(private readonly ProviderAnalytics $analytics) {}

    public function show(AnalyticsRequest $request)
    {
        $provider = $this->provider($request);
        $activityId = $request->filled('activity_id') ? $request->integer('activity_id') : null;

        // Someone else's activity is "not found", like one that does not exist.
        if ($activityId && ! Activity::where('provider_id', $provider->id)->whereKey($activityId)->exists()) {
            throw ProviderApiException::notFound('Activity not found.');
        }

        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->subDays(29);

        return $this->ok($this->analytics->report($provider, $activityId, $from, $to));
    }
}
