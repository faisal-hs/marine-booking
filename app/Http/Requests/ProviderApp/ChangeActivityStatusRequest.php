<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

class ChangeActivityStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** "inactive" is the spec's third name for paused; they are the same state. */
    public function rules(): array
    {
        return ['status' => ['required', 'string', 'in:active,paused,inactive']];
    }

    /** What the provider asked for, as one of the two things that can be done. */
    public function target(): string
    {
        return $this->validated('status') === 'active' ? 'active' : 'paused';
    }
}
