<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\ListActivityReviewsRequest;
use App\Http\Requests\CustomerApp\SearchActivitiesRequest;
use App\Services\ActivityViewTracker;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\CustomerApp\ActivityDetails;
use App\Services\CustomerApp\Checkout;
use App\Http\Requests\CustomerApp\AvailabilityRequest;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;

/** Searching, and one activity's page and reviews. Only activities a customer can see and book exist here. */
class ActivityController extends CustomerAppController
{
    public function __construct(private readonly ActivityCatalog $catalog, private readonly ActivityDetails $details, private readonly ActivityViewTracker $views, private readonly Checkout $checkout) {}

    public function index(SearchActivitiesRequest $request)
    {
        $page = $this->catalog->search($request->validated())->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));
        $favorites = $this->catalog->favoritesAmong($this->viewer($request)?->id, $page->items());

        return $this->page($page, collect($page->items())->map(fn ($a) => $this->catalog->card($a, $favorites))->all());
    }

    public function show(Request $request, string $id)
    {
        $activity = $this->catalog->find($id) ?? throw ProviderApiException::notFound('Activity not found.');
        $activity->loadMissing('provider');

        // Opening an activity is a page view for the provider's analytics (one per visitor per day; robots and the provider themselves do not count).
        $this->views->record($activity, $request);

        return $this->ok($this->details->build($activity, $this->viewer($request)));
    }

    /** The days of a window (14 days from today unless asked, at most 62) with each departure still to come and the places left on it. */
    public function availability(AvailabilityRequest $request, string $id)
    {
        $activity = $this->catalog->find($id) ?? throw ProviderApiException::notFound('Activity not found.');
        $tz = Checkout::tz();
        // "Today" is today in the platform's timezone, as a plain date.
        $today = Carbon::parse(Carbon::now($tz)->toDateString())->startOfDay();

        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : $today->copy();
        $from = $from->lt($today) ? $today->copy() : $from;
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->startOfDay() : $from->copy()->addDays(13);

        return $this->ok($to->lt($from) ? ['timezone' => $tz, 'max_guests' => $activity->max_participants, 'days' => []] : $this->checkout->availability($activity, $from, $to));
    }

    public function reviews(ListActivityReviewsRequest $request, string $id)
    {
        $activity = $this->catalog->find($id) ?? throw ProviderApiException::notFound('Activity not found.');

        $query = $this->catalog->visibleReviews($activity)->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')));
        $page = $query->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));

        return $this->page($page, collect($page->items())->map(fn ($r) => $this->catalog->review($r))->all());
    }
}
