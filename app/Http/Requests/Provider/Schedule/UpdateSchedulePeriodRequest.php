<?php

namespace App\Http\Requests\Provider\Schedule;

use App\Models\ActivitySchedulePeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSchedulePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['sometimes', 'string', Rule::in(array_values(ActivitySchedulePeriod::dayNames()))],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function payload(): array
    {
        $data = $this->only(['day_of_week', 'start_time', 'end_time', 'instructions', 'status', 'sort_order']);

        if (isset($data['day_of_week'])) {
            $data['day_of_week'] = ActivitySchedulePeriod::dayNumberFromName($data['day_of_week']);
        }

        return $data;
    }
}
