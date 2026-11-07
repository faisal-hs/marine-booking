<?php

namespace App\Http\Requests\ProviderApp;

use App\Models\Withdrawal;

class ListWithdrawalsRequest extends ProviderPaginationRequest
{
    public function rules(): array
    {
        return parent::rules() + ['status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Withdrawal::STATUSES)]];
    }
}
