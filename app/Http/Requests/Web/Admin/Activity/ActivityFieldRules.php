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
            'max_participants' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public static function payload(array $validated): array
    {
        return $validated;
    }
}
