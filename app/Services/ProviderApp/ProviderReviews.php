<?php

namespace App\Services\ProviderApp;

use App\Models\Provider;
use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** What a provider sees of the reviews of their activities: the summary, how the ratings are spread, and the reviews with their replies. Hidden reviews are not counted. */
class ProviderReviews
{
    private function base(Provider $provider, ?int $activityId): Builder
    {
        return Review::query()->where('is_hidden', false)
            ->whereHas('activity', fn ($q) => $q->where('provider_id', $provider->id)->when($activityId, fn ($a) => $a->where('activities.id', $activityId)));
    }

    /** @return array{rating: float, reviews_count: int, rating_distribution: array<string, int>} */
    public function summary(Provider $provider, ?int $activityId): array
    {
        $counts = $this->base($provider, $activityId)->selectRaw('rating, COUNT(*) AS total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $counts->sum();
        $sum = $counts->map(fn ($total, $rating) => $total * $rating)->sum();

        return [
            'rating' => $count > 0 ? round($sum / $count, 2) : 0.0,
            'reviews_count' => $count,
            // Every star is listed, 5 down to 1, even when nobody gave it.
            'rating_distribution' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($star) => [(string) $star => (int) ($counts[$star] ?? 0)])->all(),
        ];
    }

    public function page(Provider $provider, ?int $activityId, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->base($provider, $activityId)->with(['customer.user', 'activity', 'reply'])->latest()->latest('id')->paginate($perPage, ['*'], 'page', $page);
    }
}
