<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ActivityImageService
{
    /**
     * @param  UploadedFile[]  $files
     * @return \Illuminate\Support\Collection<int, ActivityImage>
     */
    public function upload(Activity $activity, array $files): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($activity, $files) {
            $hasCover = $activity->images()->where('is_cover', true)->exists();
            $nextSortOrder = ($activity->images()->max('sort_order') ?? -1) + 1;

            $created = collect();

            foreach ($files as $index => $file) {
                $path = $file->store('activities', 'public');

                $created->push($activity->images()->create([
                    'path' => $path,
                    'sort_order' => $nextSortOrder + $index,
                    // The very first image ever uploaded for an activity becomes
                    // the cover automatically — an activity should never have
                    // images but no designated cover.
                    'is_cover' => ! $hasCover && $index === 0,
                ]));
            }

            return $created;
        });
    }

    public function delete(ActivityImage $image): void
    {
        DB::transaction(function () use ($image) {
            $activity = $image->activity;
            $wasCover = $image->is_cover;

            Storage::disk('public')->delete($image->path);
            $image->delete();

            if ($wasCover) {
                $activity->images()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
            }
        });
    }

    public function setCover(Activity $activity, ActivityImage $image): void
    {
        DB::transaction(function () use ($activity, $image) {
            $activity->images()->where('id', '!=', $image->id)->update(['is_cover' => false]);
            $image->update(['is_cover' => true]);
        });
    }

    /**
     * @param  array<int, string>  $orderedUuids
     */
    public function reorder(Activity $activity, array $orderedUuids): void
    {
        DB::transaction(function () use ($activity, $orderedUuids) {
            foreach ($orderedUuids as $position => $uuid) {
                $activity->images()->where('uuid', $uuid)->update(['sort_order' => $position]);
            }
        });
    }
}
