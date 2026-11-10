<?php

namespace App\Http\Requests\CustomerApp;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Deleting an account cannot be undone, so it has to be asked for in so many words.
        return ['confirm' => ['required', 'accepted']];
    }
}
