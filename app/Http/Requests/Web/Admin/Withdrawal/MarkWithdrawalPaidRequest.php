<?php

namespace App\Http\Requests\Web\Admin\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

/** An admin has made the transfer and records its reference (the bank's transfer number). */
class MarkWithdrawalPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['payout_reference' => ['required', 'string', 'min:3', 'max:190']];
    }
}
