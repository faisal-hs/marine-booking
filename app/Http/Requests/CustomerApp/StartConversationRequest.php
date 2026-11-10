<?php

namespace App\Http\Requests\CustomerApp;

use Illuminate\Foundation\Http\FormRequest;

class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['provider_id' => ['required', 'integer', 'min:1'], 'activity_id' => ['required', 'integer', 'min:1']];
    }
}
