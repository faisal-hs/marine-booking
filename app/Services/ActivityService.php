<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Provider;
use App\Repositories\Contracts\ActivityFeatureValueRepositoryInterface;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivityService
{
    public function __construct(
        protected ActivityRepositoryInterface $repository,
        protected ActivityFeatureValueRepositoryInterface $featureValueRepository,
    ) {}

    public function create(Provider $provider, array $data): Activity
    {
        return DB::transaction(function () use ($provider, $data) {
            $data['provider_id'] = $provider->id;
            $data['slug'] = $this->generateUniqueSlug($data['title']);

            return $this->repository->create($data);
        });
    }

    public function update(Activity $activity, array $data): Activity
    {
        return DB::transaction(function () use ($activity, $data) {
            if (isset($data['title']) && $data['title'] !== $activity->title) {
                $data['slug'] = $this->generateUniqueSlug($data['title'], $activity->id);
            }

            $categoryChanging = isset($data['category_id']) && (int) $data['category_id'] !== $activity->category_id;

            $updated = $this->repository->update($activity, $data);

            if ($categoryChanging) {
                // Feature values are only meaningful within the category they
                // were collected under — changing category invalidates them
                // rather than silently leaving stale answers attached to a
                // now-unrelated category's feature definitions.
                $this->featureValueRepository->deleteForActivity($activity->id);
            }

            return $updated;
        });
    }

    public function delete(Activity $activity): void
    {
        $this->repository->delete($activity);
    }

    public function submitForReview(Activity $activity): Activity
    {
        if ($activity->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Only draft activities can be submitted for review.'],
            ]);
        }

        return $this->repository->update($activity, ['status' => 'pending_review']);
    }

    protected function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $suffix = 1;

        while ($this->repository->slugExists($slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
