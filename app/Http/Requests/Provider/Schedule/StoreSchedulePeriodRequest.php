<?php

namespace App\Http\Requests\Provider\Schedule;

use App\Models\ActivitySchedulePeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSchedulePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['required', 'string', Rule::in(array_values(ActivitySchedulePeriod::dayNames()))],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'price' => ['required', 'numeric', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Converts the human-readable day name ('saturday') into the stored
     * integer convention (6) — Controllers/Services never see day names,
     * only this Request's payload() output.
     */
    public function payload(): array
    {
        $data = $this->only(['day_of_week', 'start_time', 'end_time', 'price', 'instructions', 'status', 'sort_order']);

        if (isset($data['day_of_week'])) {
            $data['day_of_week'] = ActivitySchedulePeriod::dayNumberFromName($data['day_of_week']);
        }

        return $data;
    }
}
