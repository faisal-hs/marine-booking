<?php

namespace App\Http\Requests\Provider\ActivityImage;

use Illuminate\Foundation\Http\FormRequest;

class ReorderActivityImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['string'],
        ];
    }
}
