<?php

namespace App\Http\Requests\Admin\Storage;

use Illuminate\Foundation\Http\FormRequest;

/** Only staff with the `system.storage_link` permission (the super admin) may look at or make the storage link. `force` replaces a link that points somewhere else. */
class StorageLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.storage_link');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('force') && is_string($this->input('force'))) {
            $this->merge(['force' => filter_var($this->input('force'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->input('force')]);
        }
    }

    public function rules(): array
    {
        return ['force' => ['sometimes', 'nullable', 'boolean']];
    }
}
