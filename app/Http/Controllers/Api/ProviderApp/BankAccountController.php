<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\UpdateBankAccountRequest;
use App\Models\Provider;
use Illuminate\Http\Request;

/** The account withdrawals are paid into. The full IBAN is never returned, only its last four characters. */
class BankAccountController extends ProviderAppController
{
    public function show(Request $request)
    {
        $account = $this->provider($request)->bank_account;

        if (! $account) {
            throw ProviderApiException::notFound('No bank account has been added yet.');
        }

        return $this->ok($this->describe($account));
    }

    public function update(UpdateBankAccountRequest $request)
    {
        $provider = $this->provider($request);

        // No admin approval is required to change it (undecided); a withdrawal keeps a copy of the account it was asked for.
        $provider->update(['bank_account' => $request->validated() + ['verification_status' => 'approved', 'updated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]]);

        return $this->ok($this->describe($provider->refresh()->bank_account), 'Bank account saved');
    }

    /** @param  array<string, mixed>  $account */
    private function describe(array $account): array
    {
        return [
            'account_holder_name' => $account['account_holder_name'],
            'bank_name' => $account['bank_name'],
            'iban_last4' => substr($account['iban'], -4),
            'fee_percent' => (float) config('wallet.withdrawal_fee_percent'),
            'verification_status' => $account['verification_status'] ?? 'approved',
        ];
    }
}
