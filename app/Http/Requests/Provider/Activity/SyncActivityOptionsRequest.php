<?php

namespace App\Http\Requests\Provider\Activity;

use Illuminate\Foundation\Http\FormRequest;

class SyncActivityOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'options' => ['present', 'array'],
            'options.*.name' => ['required', 'string', 'max:255'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_required' => ['boolean'],
            'options.*.max_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
