<?php

namespace App\Http\Requests\Provider\Activity;

use Illuminate\Foundation\Http\FormRequest;

class SyncActivityRequirementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requirements' => ['present', 'array'],
            'requirements.*.text' => ['required', 'string', 'max:255'],
            'requirements.*.is_mandatory' => ['boolean'],
        ];
    }
}
