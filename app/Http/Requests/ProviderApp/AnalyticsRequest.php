<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
{
    /** The longest period a report covers, in days. */
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activity_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled('from') && $this->filled('to') && ! $validator->errors()->hasAny(['from', 'to'])
                && \Carbon\Carbon::parse($this->input('from'))->diffInDays(\Carbon\Carbon::parse($this->input('to'))) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'A report covers at most '.self::MAX_DAYS.' days.');
            }
        }];
    }
}
