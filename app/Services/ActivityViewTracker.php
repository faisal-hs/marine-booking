<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts page views for a provider's analytics: one VISITOR on one DAY is one view (a refresh adds nothing). The visitor is a hash of the signed-in
 * account, or of the address and browser, with the application key, so it cannot be turned back into a person. The provider's own looks at their own
 * activity, and obvious robots, do not count. `?source=` (or the X-Booking-Source header) says how the visitor arrived.
 */
class ActivityViewTracker
{
    public function record(Activity $activity, Request $request): void
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user && $activity->provider->user_id === $user->id) {
            return;
        }

        if (preg_match('/bot|crawl|spider|slurp|preview|monitor/i', (string) $request->userAgent())) {
            return;
        }

        DB::table('activity_views')->insertOrIgnore([
            'activity_id' => $activity->id,
            'visitor_hash' => sha1(config('app.key').'|'.($user ? 'user:'.$user->id : 'guest:'.$request->ip().'|'.$request->userAgent())),
            'viewed_on' => now()->toDateString(),
            'source' => self::source($request),
            'created_at' => now(),
        ]);
    }

    /** One of the four known sources; anything else (or nothing) is a direct visit. */
    public static function source(Request $request): string
    {
        $given = strtolower((string) ($request->query('source') ?? $request->header('X-Booking-Source') ?? $request->input('source', '')));

        return in_array($given, ActivityView::SOURCES, true) ? $given : 'direct';
    }
}
