<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['year' => ['required', 'integer', 'between:2000,2100']];
    }
}
