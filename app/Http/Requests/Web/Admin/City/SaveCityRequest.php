<?php

namespace App\Http\Requests\Web\Admin\City;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active'), 'slug' => $this->filled('slug') ? strtolower(trim($this->input('slug'))) : null]);
    }

    public function rules(): array
    {
        $city = $this->route('city');

        return [
            'name_en' => ['required', 'string', 'min:2', 'max:120'],
            'name_ar' => ['required', 'string', 'min:2', 'max:120'],
            // Optional: made from the English name when left empty.
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('cities', 'slug')->ignore($city?->id)],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }
}
