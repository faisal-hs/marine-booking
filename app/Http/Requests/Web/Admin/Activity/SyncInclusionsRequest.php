<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncInclusionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.update');
    }

    /** A row with no description is just an unused empty row on the form. */
    protected function prepareForValidation(): void
    {
        $rows = collect((array) $this->input('inclusions', []))
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['description'] ?? '')) !== '')
            ->values()
            ->all();

        $this->merge(['inclusions' => $rows]);
    }

    public function rules(): array
    {
        return [
            'inclusions' => ['present', 'array', 'max:50'],
            'inclusions.*.type' => ['required', Rule::in(['included', 'excluded'])],
            'inclusions.*.description' => ['required', 'string', 'max:255'],
        ];
    }

    public function payload(): array
    {
        return array_values($this->validated()['inclusions'] ?? []);
    }
}
