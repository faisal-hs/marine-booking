<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;

class RequestWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = max(0.01, (float) config('wallet.min_withdrawal'));

        return [
            'amount' => ['required', 'numeric', 'min:'.$min],
            // The client's own key for this request: sending it twice must never reserve the money twice.
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9._:\-]+$/'],
        ];
    }
}
