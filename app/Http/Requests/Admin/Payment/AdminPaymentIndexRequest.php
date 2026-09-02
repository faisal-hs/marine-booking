<?php

namespace App\Http\Requests\Admin\Payment;

use App\Http\Requests\PaginationRequest;
use Illuminate\Validation\Rule;

class AdminPaymentIndexRequest extends PaginationRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', Rule::in(['pending', 'succeeded', 'failed', 'refunded', 'partially_refunded'])],
            'gateway' => ['sometimes', 'string'],
            'search' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest'])],
        ]);
    }

    public function filters(): array
    {
        return $this->only(['status', 'gateway', 'search', 'sort']);
    }
}
