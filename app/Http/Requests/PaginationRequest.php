<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared pagination query-string handling for every paginated API listing
 * endpoint. The client sends `per_page` and `current_page` (not Laravel's
 * default `page`) — the custom page-name is applied at the repository
 * layer via ->paginate($perPage, ['*'], 'current_page').
 *
 * Extend this from any future paginated listing Request rather than
 * duplicating these two rules.
 */
class PaginationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'current_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(int $default = 15): int
    {
        return (int) $this->input('per_page', $default);
    }
}
