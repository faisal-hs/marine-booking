<?php

namespace App\Http\Requests\Provider\ActivityImage;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['image', 'max:4096'],
        ];
    }
}
