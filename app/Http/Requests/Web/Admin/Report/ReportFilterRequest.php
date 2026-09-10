<?php

namespace App\Http\Requests\Web\Admin\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'string', Rule::in(['last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'this_year', 'custom'])],
            'from' => ['required_if:preset,custom', 'nullable', 'date'],
            'to' => ['required_if:preset,custom', 'nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string} [from, to, preset]
     */
    public function dateRange(): array
    {
        $preset = $this->input('preset', 'last_30_days');

        if ($preset === 'custom' && $this->filled('from') && $this->filled('to')) {
            return [
                Carbon::parse($this->input('from'))->startOfDay(),
                Carbon::parse($this->input('to'))->endOfDay(),
                'custom',
            ];
        }

        [$from, $to] = match ($preset) {
            'last_7_days' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'last_90_days' => [now()->subDays(89)->startOfDay(), now()->endOfDay()],
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'this_year' => [now()->startOfYear(), now()->endOfDay()],
            default => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
        };

        return [$from, $to, $preset === 'custom' ? 'last_30_days' : $preset];
    }
}
