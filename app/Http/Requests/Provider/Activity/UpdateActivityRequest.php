<?php

namespace App\Http\Requests\Provider\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('min_participants')) {
            $this->merge([
                'min_participants' => $this->route('activity')?->min_participants ?? 1,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where('status', 'active')],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_point' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_participants' => ['integer', 'min:1'],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'gte:min_participants'],
            'min_age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
        ];
    }

    public function payload(): array
    {
        return $this->only([
            'category_id',
            'title',
            'description',
            'location',
            'meeting_point',
            'lat',
            'lng',
            'duration_minutes',
            'min_participants',
            'max_participants',
            'min_age',
        ]);
    }
}
