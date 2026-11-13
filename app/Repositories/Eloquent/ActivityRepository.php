<?php

namespace App\Repositories\Eloquent;

use App\Models\Activity;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class ActivityRepository extends BaseRepository implements ActivityRepositoryInterface
{
    public function __construct(Activity $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Activity
    {
        return $this->model->newQuery()->where('slug', $slug)->first();
    }

    public function findByIdOrUuidOrSlug(string $value): ?Activity
    {
        if (is_numeric($value)) {
            return $this->model->newQuery()->find((int) $value);
        }

        if (Str::isUuid($value)) {
            return $this->findByUuid($value);
        }

        return $this->findBySlug($value);
    }

    public function deactivatePublishedForProvider(int $providerId): int
    {
        return $this->model->newQuery()
            ->where('provider_id', $providerId)
            ->where('status', 'published')
            ->update(['status' => 'inactive']);
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        // withTrashed(): a deleted row still owns its slug, because the unique index on the column
        // counts it. Without this, deleting something and then creating another with the same name
        // collided with the deleted row and failed with a raw SQL error (HTTP 500).
        return $this->model->newQuery()->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * The tabs of a provider's activity list, by the status an activity has:
     *   active  published: live for customers
     *   paused  inactive (paused by the provider, or stopped by an admin: those say `suspended: true`) and approved (ready, waiting for the provider to
     *           turn it on): in both, customers cannot see it
     *   draft   draft (and the retired review states, which are drafts again)
     */
    public const STATUS_GROUPS = [
        'draft' => ['draft', 'rejected', 'pending_review'],
        'active' => ['published'],
        'paused' => ['inactive', 'approved'],
    ];

    /** @return array{all: int, active: int, draft: int, paused: int} every activity of the provider, whatever tab is open */
    public function countsForProvider(int $providerId): array
    {
        $byStatus = $this->model->newQuery()->where('provider_id', $providerId)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $in = fn (string $group) => (int) collect(self::STATUS_GROUPS[$group])->sum(fn ($s) => $byStatus[$s] ?? 0);

        return ['all' => (int) $byStatus->sum(), 'active' => $in('active'), 'draft' => $in('draft'), 'paused' => $in('paused')];
    }

    public function paginateForProvider(int $providerId, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = \App\Services\ProviderApp\ActivityCardStats::select($this->model->newQuery())
            ->where('provider_id', $providerId)
            ->with(['category', 'images', 'featureValues.featureDefinition', 'options', 'requirements', 'inclusions', 'schedulePeriods', 'availabilityOverrides']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['filter']) && $filters['filter'] !== 'all') {
            $query->whereIn('status', self::STATUS_GROUPS[$filters['filter']]);
        }

        return $query->latest()->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    public function paginatePublished(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->where('status', 'published')
            ->with(['category', 'provider', 'images']);

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $query->where('title', 'like', '%'.$filters['search'].'%');
        }

        if (! empty($filters['location'])) {
            $query->where('location', 'like', '%'.$filters['location'].'%');
        }

        match ($filters['sort'] ?? 'newest') {
            'rating' => $query->orderByDesc('average_rating')->orderByDesc('reviews_count'),
            default => $query->latest(),
        };

        return $query->paginate($perPage, ['*'], 'current_page')->withQueryString();
    }

    /**
     * Homepage "Featured Activities" — simply the most recently published,
     * since there is no dedicated is_featured flag on the schema. A
     * reasonable, pragmatic choice rather than a schema change for this
     * first pass.
     */
    public function featured(int $limit = 8)
    {
        return $this->model->newQuery()
            ->where('status', 'published')
            ->with(['category', 'images'])
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Homepage "Popular / Top Rated" — highest average_rating among
     * activities that have at least one visible review, so an unrated
     * activity (average_rating defaults to 0) never outranks a genuinely
     * well-reviewed one.
     */
    public function topRated(int $limit = 8)
    {
        return $this->model->newQuery()
            ->where('status', 'published')
            ->where('reviews_count', '>', 0)
            ->with(['category', 'images'])
            ->orderByDesc('average_rating')
            ->orderByDesc('reviews_count')
            ->limit($limit)
            ->get();
    }

    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->with(['category', 'provider']);

        // "suspended" is not a status: it is an admin's suspension, kept apart from the status (see ActivityPublishingService).
        if (($filters['status'] ?? null) === 'suspended') {
            $query->whereNotNull('suspended_at');
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Deleted activities are hidden unless asked for.
        if (($filters['trashed'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';

            $query->where(fn ($q) => $q->where('title', 'like', $term)
                ->orWhereHas('provider', fn ($p) => $p->where('business_name', 'like', $term)));
        }

        return $query->latest()->paginate($perPage)->withQueryString();
    }
}
