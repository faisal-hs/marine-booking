<?php

namespace App\Http\Requests\Provider\Activity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncActivityInclusionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inclusions' => ['present', 'array'],
            'inclusions.*.type' => ['required', Rule::in(['included', 'excluded'])],
            'inclusions.*.description' => ['required', 'string', 'max:255'],
        ];
    }
}
