<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\IteratesBulkActivities;
use Illuminate\Support\Str;

/**
 * How each activity is sold. The default option is per person for about 78% of activities, otherwise per trip, per
 * hour, fixed, per unit or per group; a third also offer a private trip, some an hourly or package option.
 * Volume at scale 1: 1 to 3 per activity, about 22,000. The default option is always active; 2% of the others
 * are soft-deleted. Bare drafts get none.
 * Prerequisites: BulkActivitySeeder.
 */
class BulkActivityBookingOptionSeeder extends BulkSeeder
{
    use IteratesBulkActivities;

    private const NAMES = ['per_person' => 'Standard', 'per_trip' => 'Private Trip', 'per_hour' => 'Per Hour', 'package' => '4 Hour Package',
        'fixed' => 'Standard Flight', 'per_unit' => 'Per Unit', 'per_group' => 'Private Group'];

    protected function seed(): void
    {
        $this->needs('activities', 'BulkActivitySeeder', fn ($q) => $q->where('slug', 'like', '%'.self::ACTIVITY_SLUG_MARK.'%'));

        $this->insert('activity_booking_options', (function () {
            foreach ($this->activitiesWithout('activity_booking_options') as $activity) {
                if ($this->isBareDraft($activity)) {
                    continue;
                }

                $models = [$this->weighted(['per_person' => 78, 'per_trip' => 7, 'per_hour' => 5, 'fixed' => 4, 'per_unit' => 3, 'per_group' => 3])];
                foreach (['per_trip' => 35, 'per_hour' => 15, 'package' => 5] as $extra => $percent) {
                    if (! in_array($extra, $models, true) && $this->chance($percent) && count($models) < 3) {
                        $models[] = $extra;
                    }
                }

                foreach ($models as $order => $model) {
                    $name = self::NAMES[$model];

                    yield [
                        'uuid' => $this->uuid('booking-option', "{$activity->id}-{$model}"),
                        'activity_id' => $activity->id,
                        'name' => $name,
                        'slug' => Str::slug($name),
                        'description' => null,
                        'booking_model' => $model,
                        'min_participants' => $model === 'per_group' ? 2 : null,
                        'max_participants' => $model === 'per_group' ? $this->rand(6, 12) : null,
                        'min_quantity' => $model === 'per_unit' ? 1 : null,
                        'max_quantity' => $model === 'per_unit' ? $this->rand(2, 6) : null,
                        'min_duration' => match ($model) { 'per_hour' => $this->rand(1, 2), 'package' => $this->rand(3, 6), default => null },
                        'max_duration' => $model === 'per_hour' ? $this->rand(4, 8) : null,
                        'duration_unit' => in_array($model, ['per_hour', 'package'], true) ? 'hour' : null,
                        'is_active' => $order === 0 || ! $this->chance(10) ? 1 : 0,
                        'is_default' => $order === 0 ? 1 : 0,
                        'sort_order' => $order,
                        'deleted_at' => $order > 0 && $this->chance(2) ? $activity->created_at : null,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->created_at,
                    ];
                }
            }
        })());
    }
}
