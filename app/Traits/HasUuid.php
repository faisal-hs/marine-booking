<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Adds a public-facing UUID to any model, auto-generated on creation.
 *
 * Route-model binding and API lookups should resolve by `uuid`, not by
 * the internal auto-incrementing `id` — this keeps sequential IDs out of
 * URLs and prevents record enumeration (see blueprint §34).
 *
 * Usage:
 *   class Activity extends Model
 *   {
 *       use HasUuid;
 *   }
 *
 * Requires a `uuid` CHAR(36) UNIQUE column on the model's table.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Resolve route-model binding by uuid instead of the primary key.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
