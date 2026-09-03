<?php

namespace App\Http\Requests\Admin\Review;

use App\Http\Requests\PaginationRequest;
use Illuminate\Validation\Rule;

class AdminReviewIndexRequest extends PaginationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'is_hidden' => ['sometimes', 'boolean'],
            'activity' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest'])],
        ]);
    }

    public function filters(): array
    {
        $filters = $this->only(['activity', 'sort']);

        if ($this->has('is_hidden')) {
            $filters['is_hidden'] = $this->boolean('is_hidden');
        }

        return $filters;
    }
}
