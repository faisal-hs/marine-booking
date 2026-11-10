<?php

namespace App\Services\CustomerApp;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Review;

/** The full activity page of the customer app. Where the platform has no data for a field, the field says so (null or empty) rather than invent it. */
class ActivityDetails
{
    public function __construct(private readonly ActivityCatalog $catalog, private readonly CancellationPolicy $policy) {}

    /** @return array<string, mixed> */
    public function build(Activity $a, ?Customer $viewer): array
    {
        $a->loadMissing(['city', 'category', 'provider', 'images', 'inclusions', 'requirements']);
        $favorites = $this->catalog->favoritesAmong($viewer?->id, [$a]);
        $images = $a->images->sortBy('sort_order')->values();
        $cover = $a->images->firstWhere('is_cover', true) ?? $images->first();
        $provider = $a->provider;
        $price = (float) $a->min_price;

        return [
            'id' => $a->id,
            'title' => $a->title,
            'city' => $this->catalog->cityName($a),
            'location' => $a->location,
            'category' => $a->category ? ['id' => $a->category->id, 'name' => $a->category->name] : null,
            'image' => $this->catalog->url($cover?->path),
            'gallery' => $images->map(fn ($i) => $this->catalog->url($i->path))->all(),
            'duration_hours' => round($a->duration_minutes / 60, 1),
            'price' => $price,
            'original_price' => null,
            'discount_percent' => null,
            'rating' => (float) $a->average_rating,
            'reviews_count' => (int) $a->reviews_count,
            'booked_count' => (int) $a->booked_count,
            'is_bestseller' => (int) $a->booked_count >= (int) config('customer_app.bestseller_min_bookings'),
            'is_premium' => (bool) $a->is_featured,
            'is_favorite' => in_array($a->id, $favorites, true),
            'free_cancellation' => $this->policy->isFree(),
            'max_guests' => $a->max_participants,
            'is_certified' => $provider?->verification_status === 'verified',
            'languages' => [],
            'description' => $a->description,
            'included' => $a->inclusions->where('type', 'included')->sortBy('sort_order')->pluck('description')->values()->all(),
            'not_included' => $a->inclusions->where('type', 'excluded')->sortBy('sort_order')->pluck('description')->values()->all(),
            'safety_instructions' => $a->requirements->sortBy('sort_order')->pluck('text')->values()->all(),
            'cancellation_rules' => $this->policy->rules(),
            // There is no weather service behind the platform, so no weather is shown rather than an invented one.
            'weather' => null,
            'meeting_point' => ['name' => $a->meeting_point, 'note' => null, 'lat' => $a->lat !== null ? (float) $a->lat : null, 'lng' => $a->lng !== null ? (float) $a->lng : null],
            'host' => $this->host($a),
            'reviews' => $this->catalog->visibleReviews($a)->limit(5)->get()->map(fn (Review $r) => $this->catalog->review($r))->all(),
            'rating_distribution' => $this->distribution($a),
            'rating_categories' => null,
            'similar' => $this->similar($a, $viewer),
        ];
    }

    /** @return array<string, mixed> */
    private function host(Activity $a): array
    {
        $p = $a->provider;
        $rating = Review::query()->where('is_hidden', false)->whereIn('activity_id', Activity::where('provider_id', $p->id)->select('id'))->avg('rating');

        return [
            'id' => $p->id,
            'name' => $p->business_name,
            'avatar' => $this->catalog->url($p->logo),
            'is_verified' => $p->verification_status === 'verified',
            'years_hosting' => (int) $p->created_at->diffInYears(now()),
            'rating' => $rating !== null ? round((float) $rating, 2) : null,
            // Nothing measures how fast a provider answers yet.
            'response_rate' => null,
            'bio' => $p->bio,
        ];
    }

    /** @return array<string, int> every star, 5 down to 1 */
    private function distribution(Activity $a): array
    {
        $counts = Review::query()->where('activity_id', $a->id)->where('is_hidden', false)->selectRaw('rating, COUNT(*) AS total')->groupBy('rating')->pluck('total', 'rating');

        return collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [(string) $s => (int) ($counts[$s] ?? 0)])->all();
    }

    /** @return list<array<string, mixed>> */
    private function similar(Activity $a, ?Customer $viewer): array
    {
        $others = $this->catalog->visible()->with(['city', 'coverImage', 'provider:id,city'])->where('activities.id', '!=', $a->id)
            ->when($a->category_id, fn ($q) => $q->where('activities.category_id', $a->category_id))->orderByDesc('activities.average_rating')->orderBy('activities.id')->limit(6)->get();
        $favorites = $this->catalog->favoritesAmong($viewer?->id, $others);

        return $others->map(fn (Activity $o) => $this->catalog->card($o, $favorites))->all();
    }
}
