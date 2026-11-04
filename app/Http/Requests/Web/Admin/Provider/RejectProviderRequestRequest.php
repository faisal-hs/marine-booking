<?php

namespace App\Http\Requests\Web\Admin\ProviderRequest;

use Illuminate\Foundation\Http\FormRequest;

class RejectProviderRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('provider_requests.review');
    }

    public function rules(): array
    {
        // The applicant reads this reason, so it cannot be empty.
        return ['rejection_reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
