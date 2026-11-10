<?php

namespace App\Http\Requests\CustomerApp;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    /** The longest window, in days. */
    public const MAX_DAYS = 62;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from']];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled('from') && $this->filled('to') && ! $validator->errors()->hasAny(['from', 'to']) && Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'Ask for at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }
}
