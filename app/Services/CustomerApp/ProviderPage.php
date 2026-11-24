<?php

namespace App\Services\CustomerApp;

use App\Models\Activity;
use App\Models\City;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Reservation;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A provider as customers see them, with no sign-in: who they are, how they have done, what customers say, and what they offer. Everything here is worked out from what the platform
 * really holds. What it does not hold is left empty rather than invented: there is no cover picture or language list on a provider, and nothing records a provider being "a favourite"
 * (only activities are), so `cover` is null, `languages` is empty and `is_favorite` is false.
 *
 * Who is shown: a VERIFIED provider whose account is active and not deleted. What is counted: the activities customers can see (published, not suspended, priced), and the reviews of
 * those that are not hidden, so the numbers always agree with the lists.
 */
class ProviderPage
{
    /** How many reviews and activities the page itself shows (the whole lists are paged apart). */
    private const RECENT_REVIEWS = 5;

    private const ACTIVITIES = 10;

    public function __construct(private readonly ActivityCatalog $catalog) {}

    /**
     * The provider customers can see, by id (or, with `$anyKey`, by uuid or slug too); null when there is none (nobody by that key, not verified, suspended, deleted).
     */
    public function find(string|int $key, bool $anyKey = false): ?Provider
    {
        $visible = fn () => Provider::query()->where('verification_status', 'verified')->whereHas('user', fn ($u) => $u->where('status', 'active'));
        $key = (string) $key;

        if (ctype_digit($key) && ($provider = $visible()->find((int) $key))) {
            return $provider;
        }

        return $anyKey ? $visible()->where(fn ($q) => $q->where('uuid', $key)->orWhere('slug', $key))->first() : null;
    }

    /** @return array<string, mixed> the page: provider, stats, rating, recent reviews and activities */
    public function profile(Provider $p, ?Customer $viewer = null): array
    {
        $rating = $this->rating($p);
        $favorites = [];
        $activities = $this->activitiesQuery($p, ['sort' => 'popular'])->limit(self::ACTIVITIES)->get();

        if ($viewer) {
            $favorites = $this->catalog->favoritesAmong($viewer->id, $activities);
        }

        return [
            'provider' => $this->provider($p, $rating),
            'stats' => $this->stats($p),
            'rating' => $rating,
            'recent_reviews' => $this->reviews($p)->limit(self::RECENT_REVIEWS)->get()->map(fn (Review $r) => $this->review($r))->all(),
            'activities' => $activities->map(fn (Activity $a) => $this->activity($a, $favorites))->all(),
        ];
    }

    /** The activities of the provider that customers can see, searched and sorted as the activity list is. @param  array<string, mixed>  $filters */
    public function activitiesQuery(Provider $p, array $filters = []): Builder
    {
        return $this->catalog->search(['provider_id' => $p->id] + $filters)->with('category');
    }

    /** Every review customers can see of the provider's visible activities, newest first. */
    public function reviews(Provider $p): Builder
    {
        return Review::query()->where('is_hidden', false)->whereIn('activity_id', $this->visibleActivityIds($p))
            ->with(['customer.user', 'reply', 'activity:id,title'])->latest()->latest('id');
    }

    /** One activity of the provider as a card, with its category. @param  list<int>  $favorites @return array<string, mixed> */
    public function activity(Activity $a, array $favorites = []): array
    {
        $card = $this->catalog->card($a, $favorites);

        return [
            'id' => $card['id'], 'title' => $card['title'], 'city' => $card['city'], 'image' => $card['image'],
            'category' => $a->category ? ['id' => $a->category->id, 'name' => $a->category->name] : null,
            'duration_hours' => $card['duration_hours'], 'rating' => $card['rating'], 'reviews_count' => $card['reviews_count'], 'price' => $card['price'],
            'price_unit' => $card['price_unit'], 'reservation_type' => $card['reservation_type'], 'is_premium' => $card['is_premium'], 'is_favorite' => $card['is_favorite'],
        ];
    }

    /** One review as the provider's page shows it. @return array<string, mixed> */
    public function review(Review $r): array
    {
        $base = $this->catalog->review($r);

        return [
            'id' => $base['id'], 'name' => $base['name'], 'avatar' => $base['avatar'], 'rating' => $base['rating'], 'text' => $base['text'], 'date' => $base['date'],
            'activity' => $r->activity ? ['id' => $r->activity->id, 'title' => $r->activity->title] : null,
            'provider_reply' => $r->reply ? ['text' => $r->reply->text, 'date' => $r->reply->created_at->utc()->format('Y-m-d\TH:i:s\Z')] : null,
        ];
    }

    // --- The parts ------------------------------------------------------------------------------------------------------------------

    /** @param  array{average: ?float, reviews_count: int, breakdown: array<string, int>}  $rating @return array<string, mixed> */
    private function provider(Provider $p, array $rating): array
    {
        $p->loadMissing('categories');

        return [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'business_name' => $p->business_name,
            'slug' => $p->slug,
            'logo' => $this->catalog->url($p->logo),
            // A provider has no cover picture of its own yet.
            'cover' => null,
            'bio' => $p->bio,
            'is_verified' => true,
            'business_type' => $p->business_type,
            'city' => $this->city($p),
            'area' => $p->area,
            'marina_name' => $p->marina_name,
            'lat' => $p->lat !== null ? (float) $p->lat : null,
            'lng' => $p->lng !== null ? (float) $p->lng : null,
            'member_since' => $p->created_at->toDateString(),
            'years_hosting' => (int) $p->created_at->diffInYears(now()),
            // Providers do not record the languages they host in.
            'languages' => [],
            'categories' => $p->categories->where('status', 'active')->sortBy(['sort_order', 'id'])->values()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'icon' => $this->catalog->url($c->image)])->all(),
            'badges' => $this->badges($p, $rating),
            // Only activities can be saved as favourites, not providers.
            'is_favorite' => false,
        ];
    }

    /** The provider's city: the platform's own city when the name matches one (so the app gets its id and both names), otherwise the name as the provider wrote it. @return array<string, mixed>|null */
    private function city(Provider $p): ?array
    {
        $name = trim((string) $p->city);

        if ($name === '') {
            return null;
        }

        $city = City::query()->whereRaw('LOWER(name_en) = ?', [mb_strtolower($name)])->orWhereRaw('LOWER(name_ar) = ?', [mb_strtolower($name)])->first();

        return ['id' => $city?->id, 'name_ar' => $city?->name_ar ?? $name, 'name_en' => $city?->name_en ?? $name];
    }

    /**
     * What an admin has approved (a safety certificate, insurance: not expired, not replaced) and how customers rate the provider.
     *
     * @param  array{average: ?float, reviews_count: int, breakdown: array<string, int>}  $rating
     * @return list<string>
     */
    private function badges(Provider $p, array $rating): array
    {
        $approved = $p->documents()->where('status', ProviderDocument::APPROVED)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('type')->all();
        $badges = [];

        if (in_array('safety_certification', $approved, true)) {
            $badges[] = 'safety_certified';
        }

        if (array_intersect(['insurance', 'insurance_certificate'], $approved)) {
            $badges[] = 'insured';
        }

        if ($rating['reviews_count'] >= (int) config('customer_app.top_rated_min_reviews') && $rating['average'] !== null && $rating['average'] >= (float) config('customer_app.top_rated_min_rating')) {
            $badges[] = 'top_rated';
        }

        return $badges;
    }

    /** @return array{average: ?float, reviews_count: int, breakdown: array<string, int>} */
    public function rating(Provider $p): array
    {
        $counts = Review::query()->where('is_hidden', false)->whereIn('activity_id', $this->visibleActivityIds($p))->selectRaw('rating, COUNT(*) AS total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $counts->sum();
        $sum = $counts->map(fn ($total, $stars) => $total * $stars)->sum();

        return [
            'average' => $count > 0 ? round($sum / $count, 2) : null,
            'reviews_count' => $count,
            'breakdown' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [(string) $s => (int) ($counts[$s] ?? 0)])->all(),
        ];
    }

    /**
     * What the provider has done: the activities customers can see, the trips completed and the guests hosted (over all their activities, as history), and how they answer customers
     * (measured from real conversations: see responses()). Worked out at most once in `customer_app.provider_stats_cache_seconds`.
     *
     * @return array<string, mixed>
     */
    private function stats(Provider $p): array
    {
        $work = function () use ($p) {
            $completed = Reservation::query()->whereIn('activity_id', Activity::query()->where('provider_id', $p->id)->select('id'))->where('status', 'completed');
            $guests = 0;
            (clone $completed)->select(['id', 'participants'])->chunkById(2000, function ($rows) use (&$guests) {
                foreach ($rows as $row) {
                    $guests += (int) array_sum((array) $row->participants);
                }
            });

            return [
                'activities_count' => $this->visibleActivityIds($p)->count(),
                'completed_trips' => (clone $completed)->count(),
                'guests_hosted' => $guests,
            ] + $this->responses($p);
        };

        $ttl = (int) config('customer_app.provider_stats_cache_seconds');

        return $ttl > 0 ? Cache::remember("customer-app:provider-stats:{$p->id}", $ttl, $work) : $work();
    }

    /**
     * How the provider answers customers, over the conversations customers began in the last `customer_app.response_window_days`: the share that got an answer from the provider
     * (`response_rate`, a percentage) and how long the first answer took on average (`response_time_minutes`). Both are null when there were no conversations: nothing to measure.
     *
     * @return array{response_rate: ?int, response_time_minutes: ?int}
     */
    private function responses(Provider $p): array
    {
        $since = now()->subDays((int) config('customer_app.response_window_days'));

        // The conversations customers began in the window, and when the customer first wrote in each.
        $began = DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')->where('c.provider_id', $p->id)->where('m.sender_type', Message::CUSTOMER)
            ->groupBy('m.conversation_id')->havingRaw('MIN(m.created_at) >= ?', [$since->toDateTimeString()])->selectRaw('m.conversation_id, MIN(m.created_at) AS first_customer')
            ->pluck('first_customer', 'conversation_id');

        if ($began->isEmpty()) {
            return ['response_rate' => null, 'response_time_minutes' => null];
        }

        // The first thing the provider said AFTER the customer first wrote (something said before is not an answer).
        $answered = [];
        foreach ($began->keys()->chunk(500) as $ids) {
            foreach (DB::table('messages')->whereIn('conversation_id', $ids->all())->where('sender_type', Message::PROVIDER)->orderBy('created_at')->get(['conversation_id', 'created_at']) as $m) {
                if (! isset($answered[$m->conversation_id]) && $m->created_at >= $began[$m->conversation_id]) {
                    $answered[$m->conversation_id] = Carbon::parse($began[$m->conversation_id])->diffInMinutes(Carbon::parse($m->created_at), true);
                }
            }
        }

        return [
            'response_rate' => (int) round(count($answered) / $began->count() * 100),
            'response_time_minutes' => $answered === [] ? null : (int) round(array_sum($answered) / count($answered)),
        ];
    }

    /** The ids of the provider's activities that customers can see. */
    private function visibleActivityIds(Provider $p): Builder
    {
        return $this->catalog->visible()->where('activities.provider_id', $p->id)->reorder()->select('activities.id');
    }
}
