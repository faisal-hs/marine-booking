<?php

namespace App\Http\Requests\Web\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;

class StoreImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('activities.update');
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            // Raster formats only, spelled out. An SVG can carry a script, and photos are
            // served from the public disk, so the allowed list is explicit rather than left
            // to the framework's default for the generic "image" rule.
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
