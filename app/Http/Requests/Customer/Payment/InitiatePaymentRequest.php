<?php

namespace App\Http\Requests\Customer\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 'simulate' is only meaningful for the mock gateway (drives which
     * of its three simulated outcomes to return) — a real gateway would
     * have its own genuinely different params here (payment method
     * token, etc.), which is exactly why this stays a loose passthrough
     * rather than a rigid contract.
     */
    public function rules(): array
    {
        return [
            'simulate' => ['sometimes', 'string', Rule::in(['sync_success', 'sync_failure', 'async'])],
        ];
    }

    public function params(): array
    {
        return $this->only(['simulate']);
    }
}
