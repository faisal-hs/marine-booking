<?php

namespace App\Http\Requests\CustomerApp;

use App\Services\CustomerApp\ActivityCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // At least the minimum, but only when a minimum was given: a ceiling on its own is a perfectly good filter.
            'max_price' => ['sometimes', 'nullable', 'numeric', 'min:0', Rule::when($this->filled('min_price'), ['gte:min_price'])],
            'max_duration' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:240'],
            'min_rating' => ['sometimes', 'nullable', 'numeric', 'between:0,5'],
            'sort' => ['sometimes', 'nullable', Rule::in(ActivityCatalog::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }
}
