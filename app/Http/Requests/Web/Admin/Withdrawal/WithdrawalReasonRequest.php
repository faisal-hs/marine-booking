<?php

namespace App\Http\Requests\Web\Admin\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

/** Why a withdrawal was rejected or failed: the provider is told it. */
class WithdrawalReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
