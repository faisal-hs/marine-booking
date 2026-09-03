<?php

namespace App\Http\Requests\Web\Admin\Provider;

use Illuminate\Foundation\Http\FormRequest;

class RejectProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
