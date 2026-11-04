<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;

class SyncRequirementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.update');
    }

    /** The form always shows a few empty rows; a row with no text is simply not a requirement. */
    protected function prepareForValidation(): void
    {
        $rows = collect((array) $this->input('requirements', []))
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['text'] ?? '')) !== '')
            ->values()
            ->all();

        $this->merge(['requirements' => $rows]);
    }

    public function rules(): array
    {
        return [
            'requirements' => ['present', 'array', 'max:30'],
            'requirements.*.text' => ['required', 'string', 'max:255'],
            'requirements.*.is_mandatory' => ['nullable', 'boolean'],
        ];
    }

    public function payload(): array
    {
        return collect($this->validated()['requirements'] ?? [])
            ->map(fn ($row) => ['text' => $row['text'], 'is_mandatory' => (bool) ($row['is_mandatory'] ?? false)])
            ->all();
    }
}
