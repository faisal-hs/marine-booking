<?php

namespace App\Http\Requests\CustomerApp;

use App\Services\CustomerApp\Checkout;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    /** The longest window, in days. */
    public const MAX_DAYS = 62;

    public const MAX_MONTHS_AHEAD = 12;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A whole month ("2026-12"): every day of it. The current month when nothing is asked; `from` and `to` (a window of up to 62 days) win when they are sent.
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // The places left are those of this price option (see price_options on the activity); the default option's when it is not sent.
            'booking_option_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled('month') && ! $validator->errors()->has('month')) {
                $current = Carbon::now(Checkout::tz())->startOfMonth()->format('Y-m');
                $last = Carbon::now(Checkout::tz())->startOfMonth()->addMonths(self::MAX_MONTHS_AHEAD)->format('Y-m');

                if ($this->input('month') < $current || $this->input('month') > $last) {
                    $validator->errors()->add('month', "Ask for this month ({$current}) or one of the next ".self::MAX_MONTHS_AHEAD.' months (up to '.$last.').');
                }
            }

            if ($this->filled('from') && $this->filled('to') && ! $validator->errors()->hasAny(['from', 'to']) && Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'Ask for at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }
}
