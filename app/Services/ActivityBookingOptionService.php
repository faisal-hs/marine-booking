<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityBookingOption;
use App\Repositories\Contracts\ActivityBookingOptionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityBookingOptionService
{
    public function __construct(protected ActivityBookingOptionRepositoryInterface $repository) {}

    public function create(Activity $activity, array $data): ActivityBookingOption
    {
        return DB::transaction(function () use ($activity, $data) {
            $data['activity_id'] = $activity->id;
            $data['slug'] = $this->generateUniqueSlug($activity->id, $data['name']);

            if (! empty($data['is_default'])) {
                $this->repository->demoteExistingDefaults($activity->id);
            }

            return $this->repository->create($data);
        });
    }

    public function update(ActivityBookingOption $option, array $data): ActivityBookingOption
    {
        return DB::transaction(function () use ($option, $data) {
            if (isset($data['name']) && $data['name'] !== $option->name) {
                $data['slug'] = $this->generateUniqueSlug($option->activity_id, $data['name'], $option->id);
            }

            if (! empty($data['is_default'])) {
                $this->repository->demoteExistingDefaults($option->activity_id, $option->id);
            }

            return $this->repository->update($option, $data);
        });
    }

    public function delete(ActivityBookingOption $option): void
    {
        $this->repository->delete($option);
    }

    protected function generateUniqueSlug(int $activityId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while ($this->repository->slugExistsForActivity($activityId, $slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
