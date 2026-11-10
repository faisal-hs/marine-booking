<?php

namespace App\Services\CustomerApp;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Models\Favorite;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * What the customer app shows of an activity, defined once so every list, filter, sort and detail page agrees.
 *
 * VISIBLE: published (live), not suspended by an admin, and BOOKABLE: it has a default booking option (an active per-person one: the one marked default,
 * else the first) with an adult price. The customer app books per-person activities; one with only other booking models is not shown.
 *
 * PRICE: the lowest active adult price of that default option. There is no "original price" in the platform, so it is always null.
 *
 * BOOKED COUNT: confirmed and completed bookings, for the activity's whole life. POPULAR means most booked.
 */
class ActivityCatalog
{
    private const DEFAULT_OPTION_SQL = "SELECT o.id FROM activity_booking_options o WHERE o.activity_id = activities.id AND o.is_active = 1 AND o.deleted_at IS NULL AND o.booking_model = 'per_person' ORDER BY o.is_default DESC, o.sort_order ASC, o.id ASC LIMIT 1";

    public const PRICE_SQL = "(SELECT MIN(pr.price) FROM activity_pricing_rules pr WHERE pr.is_active = 1 AND pr.participant_type = 'adult' AND pr.activity_booking_option_id = (".self::DEFAULT_OPTION_SQL.'))';

    public const BOOKED_SQL = "(SELECT COUNT(*) FROM reservations rv WHERE rv.activity_id = activities.id AND rv.status IN ('confirmed', 'completed') AND rv.deleted_at IS NULL)";

    public const SORTS = ['popular', 'rating', 'price_asc', 'price_desc', 'newest', 'duration'];

    /** Every activity a customer can see and book, with `min_price` and `booked_count` worked out in the same query. */
    public function visible(): Builder
    {
        return Activity::query()->where('activities.status', 'published')->whereNull('activities.suspended_at')->whereRaw(self::PRICE_SQL.' IS NOT NULL')
            ->select('activities.*')->selectRaw(self::PRICE_SQL.' AS min_price')->selectRaw(self::BOOKED_SQL.' AS booked_count');
    }

    public function find(int|string $id): ?Activity
    {
        return ctype_digit((string) $id) ? $this->visible()->find((int) $id) : null;
    }

    /** The booking option a booking of this activity is for. */
    public function defaultOption(Activity $activity): ?ActivityBookingOption
    {
        return $activity->bookingOptions()->where('is_active', true)->where('booking_model', 'per_person')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->first();
    }

    /** @param  array<string, mixed>  $f */
    public function search(array $f): Builder
    {
        // The price filters CAST what they compare to: PDO sends a number as text, and a database that orders every number below every text (SQLite) would
        // otherwise let every activity through a maximum and none through a minimum.
        $q = $this->visible()->with(['city', 'coverImage', 'provider:id,city']);

        $q->when(! empty($f['q']), function (Builder $query) use ($f) {
            // A bound parameter, so nothing in it can change the query; a % the customer types is just a wildcard.
            $like = '%'.trim($f['q']).'%';
            $query->where(fn (Builder $w) => $w->where('activities.title', 'like', $like)->orWhere('activities.description', 'like', $like)->orWhere('activities.location', 'like', $like)
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $like))
                ->orWhereHas('city', fn ($c) => $c->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)));
        });
        $q->when(! empty($f['category_id']), fn (Builder $query) => $query->where('activities.category_id', (int) $f['category_id']));
        $q->when(! empty($f['city_id']), fn (Builder $query) => $query->where('activities.city_id', (int) $f['city_id']));
        $q->when(isset($f['min_price']), fn (Builder $query) => $query->whereRaw(self::PRICE_SQL.' >= CAST(? AS DECIMAL(12,2))', [(float) $f['min_price']]));
        $q->when(isset($f['max_price']), fn (Builder $query) => $query->whereRaw(self::PRICE_SQL.' <= CAST(? AS DECIMAL(12,2))', [(float) $f['max_price']]));
        $q->when(isset($f['max_duration']), fn (Builder $query) => $query->where('activities.duration_minutes', '<=', (float) $f['max_duration'] * 60));
        $q->when(isset($f['min_rating']), fn (Builder $query) => $query->where('activities.average_rating', '>=', (float) $f['min_rating']));

        // An allow-list: nothing the client sends is ever part of the SQL.
        match ($f['sort'] ?? 'popular') {
            'rating' => $q->orderByDesc('activities.average_rating')->orderByDesc('activities.reviews_count'),
            'price_asc' => $q->orderByRaw(self::PRICE_SQL.' ASC'),
            'price_desc' => $q->orderByRaw(self::PRICE_SQL.' DESC'),
            'newest' => $q->orderByDesc('activities.published_at'),
            'duration' => $q->orderBy('activities.duration_minutes'),
            default => $q->orderByRaw(self::BOOKED_SQL.' DESC')->orderByDesc('activities.average_rating'),
        };

        return $q->orderBy('activities.id');
    }

    /** @return list<int> the activities among these the customer has saved */
    public function favoritesAmong(?int $customerId, iterable $activities): array
    {
        if (! $customerId) {
            return [];
        }

        return Favorite::where('customer_id', $customerId)->whereIn('activity_id', collect($activities)->pluck('id'))->pluck('activity_id')->all();
    }

    public function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function cityName(Activity $a): ?string
    {
        return $a->city?->name() ?? ($a->provider?->city ?: null);
    }

    /** The card a list shows. @return array<string, mixed> */
    public function card(Activity $a, array $favorites = []): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'city' => $this->cityName($a),
            'image' => $this->url($a->coverImage?->path),
            'duration_hours' => round($a->duration_minutes / 60, 1),
            'rating' => (float) $a->average_rating,
            'reviews_count' => (int) $a->reviews_count,
            'price' => $a->min_price !== null ? (float) $a->min_price : null,
            'original_price' => null,
            'is_premium' => (bool) $a->is_featured,
            'is_favorite' => in_array($a->id, $favorites, true),
        ];
    }

    /** "Sara Al-Qahtani" is shown as "Sara A.": a customer's name is not published in full. */
    public static function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        return match (count($parts)) {
            0 => '',
            1 => $parts[0],
            default => $parts[0].' '.mb_substr(end($parts), 0, 1).'.',
        };
    }

    /** One review as customers see it. @return array<string, mixed> */
    public function review(Review $r): array
    {
        return [
            'id' => $r->id,
            // A customer's name is shown as "Sara A."; someone who deleted their account is just "Deleted customer".
            'name' => $r->customer?->user?->trashed() ? __('customer_app.deleted_customer') : self::maskName($r->customer?->user?->name),
            'avatar' => $this->url($r->customer?->user?->avatar_path),
            'date' => $r->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'rating' => $r->rating,
            'text' => $r->comment,
            'provider_reply' => $r->reply ? ['text' => $r->reply->text, 'created_at' => $r->reply->created_at->utc()->format('Y-m-d\TH:i:s\Z')] : null,
        ];
    }

    public function visibleReviews(Activity $a): Builder
    {
        return Review::query()->where('activity_id', $a->id)->where('is_hidden', false)->with(['customer.user', 'reply'])->latest()->latest('id');
    }
}
