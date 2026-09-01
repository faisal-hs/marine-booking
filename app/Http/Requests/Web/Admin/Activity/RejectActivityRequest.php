<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;

class RejectActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.reject');
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
