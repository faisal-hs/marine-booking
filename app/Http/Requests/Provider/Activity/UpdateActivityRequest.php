<?php

namespace App\Http\Requests\Provider\Activity;

use App\Models\ActivitySchedulePeriod;
use App\Services\ActivityFeatureService;
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
        $rules = [
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

            'images' => ['sometimes', 'array', 'max:10'],
            'images.*' => ['image', 'max:4096'],

            'features' => ['sometimes', 'array'],

            'options' => ['sometimes', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:255'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_required' => ['boolean'],
            'options.*.max_quantity' => ['nullable', 'integer', 'min:1'],

            'requirements' => ['sometimes', 'array'],
            'requirements.*.text' => ['required_with:requirements', 'string', 'max:255'],
            'requirements.*.is_mandatory' => ['boolean'],

            'inclusions' => ['sometimes', 'array'],
            'inclusions.*.type' => ['required_with:inclusions', Rule::in(['included', 'excluded'])],
            'inclusions.*.description' => ['required_with:inclusions', 'string', 'max:255'],

            'schedule' => ['sometimes', 'array'],
            'schedule.*.day_of_week' => ['required_with:schedule', 'string', Rule::in(array_values(ActivitySchedulePeriod::dayNames()))],
            'schedule.*.start_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.end_time' => ['required_with:schedule', 'date_format:H:i'],
            'schedule.*.price' => ['required_with:schedule', 'numeric', 'min:0'],
            'schedule.*.instructions' => ['nullable', 'string', 'max:1000'],
            'schedule.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];

        if ($this->filled('features')) {
            $categoryId = $this->filled('category_id')
                ? (int) $this->input('category_id')
                : $this->route('activity')?->category_id;

            if ($categoryId) {
                $rules = array_merge($rules, app(ActivityFeatureService::class)->buildValidationRulesForCategory($categoryId));
            }
        }

        return $rules;
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

    public function scheduleItems(): array
    {
        return collect($this->input('schedule', []))->map(function (array $item) {
            $item['day_of_week'] = ActivitySchedulePeriod::dayNumberFromName($item['day_of_week']);

            return $item;
        })->all();
    }
}
