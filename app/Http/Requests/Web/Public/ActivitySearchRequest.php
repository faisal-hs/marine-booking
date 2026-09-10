<?php

namespace App\Http\Requests\Web\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivitySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['newest', 'rating'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'search' => $this->input('search'),
            'location' => $this->input('location'),
            'category_id' => $this->input('category_id'),
            'sort' => $this->input('sort', 'newest'),
        ]);
    }
}
