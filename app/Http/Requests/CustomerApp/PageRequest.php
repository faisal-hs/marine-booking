<?php

namespace App\Http\Requests\CustomerApp;

use Illuminate\Foundation\Http\FormRequest;

/** A list that is only paged: `page`, and `per_page` (20 unless the list says otherwise). */
class PageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']];
    }

    public function perPage(int $default = 20): int
    {
        return $this->integer('per_page', $default);
    }
}
