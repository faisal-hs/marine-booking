<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class AvailabilityQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from_date' => ['sometimes', 'required_with:to_date', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'required_with:from_date', 'date_format:Y-m-d'],
            'month' => ['sometimes', 'date_format:Y-m'],
        ];
    }

    /**
     * Determines which resolution mode was requested. Precedence: an
     * explicit date range wins over month, which wins over a single date,
     * which falls back to "today" if nothing was provided at all — a
     * customer opening an activity page with no date picked yet is a
     * normal case, not an error.
     */
    public function mode(): string
    {
        if ($this->filled('from_date') && $this->filled('to_date')) {
            return 'range';
        }

        if ($this->filled('month')) {
            return 'month';
        }

        return 'date';
    }

    public function assertRangeNotTooLarge(\Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to, int $maxDays = 90): void
    {
        if ($from->diffInDays($to) > $maxDays) {
            throw ValidationException::withMessages([
                'to_date' => ["The requested date range cannot exceed {$maxDays} days."],
            ]);
        }
    }
}
