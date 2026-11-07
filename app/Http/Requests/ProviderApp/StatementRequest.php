<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

class StatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['month' => ['required', 'string', 'date_format:Y-m']];
    }
}
