<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Validation\Rule;

/** The core activity fields, validated exactly as the provider API validates them. */
final class ActivityFieldRules
{
    public static function core(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('status', 'active')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_point' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'min_participants' => ['nullable', 'integer', 'min:1'],
            'max_participants' => ['nullable', 'integer', 'gte:min_participants'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:120'],
        ];
    }

    /**
     * An empty minimum means "use the default of 1", not "none". This is applied BEFORE
     * validation: "max_participants must be at least min_participants" cannot compare
     * against an empty field and would reject a form that merely left the minimum blank.
     */
    public static function withDefaultMinimum(array $input): array
    {
        if (($input['min_participants'] ?? null) === null || $input['min_participants'] === '') {
            $input['min_participants'] = 1;
        }

        return $input;
    }

    public static function payload(array $validated): array
    {
        return $validated;
    }
}
