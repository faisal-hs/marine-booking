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
        protected ProviderVerificationGate $verificationGate,
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
        // A rejected activity can be edited and sent in again: the rejection
        // email promises exactly that ("make the necessary changes and resubmit").
        if (! in_array($activity->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or rejected activities can be submitted for review.'],
            ]);
        }

        // Customers are told every provider is verified before an activity goes
        // live, so an unverified provider cannot even enter the review queue.
        $this->verificationGate->assertVerifiedForSubmission($activity->provider);

        return $this->repository->update($activity, [
            'status' => 'pending_review',
            // The reason belonged to the previous review; this is a new one.
            'rejection_reason' => null,
        ]);
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
