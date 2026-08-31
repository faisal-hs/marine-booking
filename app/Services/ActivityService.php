<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Provider;
use App\Repositories\Contracts\ActivityRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivityService
{
    public function __construct(protected ActivityRepositoryInterface $repository) {}

    /**
     * provider_id is deliberately a required parameter here, not part of
     * $data — it must be resolved by the caller (Controller) from the
     * authenticated user, never trusted from request input (blueprint §20).
     */
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

            return $this->repository->update($activity, $data);
        });
    }

    public function delete(Activity $activity): void
    {
        $this->repository->delete($activity);
    }

    /**
     * Draft -> pending_review only. Provider self-serve submission;
     * approval/rejection is an admin action, built in Module 06.
     */
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
