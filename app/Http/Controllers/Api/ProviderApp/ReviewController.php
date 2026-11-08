<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\ListReviewsRequest;
use App\Models\Activity;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Services\ProviderApp\ProviderReviews;
use App\Services\ProviderApp\ReviewReplyService;
use Illuminate\Http\Request;

/** The reviews of the provider's activities, and the provider's replies to them. */
class ReviewController extends ProviderAppController
{
    public function __construct(private readonly ProviderReviews $reviews, private readonly ReviewReplyService $replies) {}

    public function index(ListReviewsRequest $request)
    {
        $provider = $this->provider($request);
        $activityId = $request->filled('activity_id') ? $request->integer('activity_id') : null;

        // Someone else's activity is "not found", like one that does not exist.
        if ($activityId && ! Activity::where('provider_id', $provider->id)->whereKey($activityId)->exists()) {
            throw ProviderApiException::notFound('Activity not found.');
        }

        $page = $this->reviews->page($provider, $activityId, $request->perPage(), $request->page());

        return $this->page($page, $this->reviews->summary($provider, $activityId) + [
            'reviews' => collect($page->items())->map(fn (Review $r) => [
                'id' => $r->id,
                'customer_name' => $r->customer->user->name,
                'activity' => $r->activity->title,
                'date' => $r->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'rating' => $r->rating,
                'text' => $r->comment,
                'reply' => $r->reply ? $this->describe($r->reply) : null,
            ])->all(),
        ]);
    }

    public function store(Request $request, string $id)
    {
        $provider = $this->provider($request);
        $review = $this->replies->reviewOf($provider, $id);

        $reply = $this->replies->reply($provider, $review, $this->text($request));

        return $this->created(['review_id' => $review->id, 'reply' => $this->describe($reply)], 'Reply added');
    }

    public function update(Request $request, string $id)
    {
        $review = $this->replies->reviewOf($this->provider($request), $id);

        $reply = $this->replies->edit($review, $this->text($request));

        return $this->ok(['id' => $reply->id, 'review_id' => $review->id, 'text' => $reply->text], 'Reply updated');
    }

    public function destroy(Request $request, string $id)
    {
        $review = $this->replies->reviewOf($this->provider($request), $id);

        $this->replies->delete($review, $request->user());

        return $this->ok(['review_id' => $review->id, 'reply' => null], 'Reply deleted');
    }

    /** The reply text, checked AFTER the review was found, so a made-up id and someone else's are answered alike whatever was sent. */
    private function text(Request $request): string
    {
        $request->merge(['text' => is_string($request->input('text')) ? trim($request->input('text')) : $request->input('text')]);

        return $request->validate(['text' => ['required', 'string', 'min:1', 'max:1000']])['text'];
    }

    /** @return array{id: int, text: string, created_at: string} */
    private function describe(ReviewReply $reply): array
    {
        return ['id' => $reply->id, 'text' => $reply->text, 'created_at' => $reply->created_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }
}
