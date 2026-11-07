<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\ListWithdrawalsRequest;
use App\Http\Requests\ProviderApp\RequestWithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WithdrawalService;
use Illuminate\Http\Request;

class WithdrawalController extends ProviderAppController
{
    public function __construct(private readonly WithdrawalService $withdrawals, private readonly LedgerService $ledger) {}

    /** Asks to be paid: the amount is reserved at once, an admin pays it by hand. */
    public function store(RequestWithdrawalRequest $request)
    {
        $provider = $this->provider($request);
        $withdrawal = $this->withdrawals->request($provider, (float) $request->validated('amount'), $request->validated('idempotency_key'));
        $balances = $this->ledger->balances($provider);

        return $this->created($this->describe($withdrawal) + [
            'balance' => ['available' => $balances['available'], 'pending' => $balances['pending']],
            'created_at' => $withdrawal->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ], 'Withdrawal requested');
    }

    public function index(ListWithdrawalsRequest $request)
    {
        $page = Withdrawal::query()->where('provider_id', $this->provider($request)->id)
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')->paginate($request->perPage(), ['*'], 'page', $request->page());

        return $this->page($page, collect($page->items())->map(fn (Withdrawal $w) => $this->describe($w))->all());
    }

    public function show(Request $request, string $id)
    {
        $withdrawal = ctype_digit($id) ? Withdrawal::where('provider_id', $this->provider($request)->id)->find((int) $id) : null;

        if (! $withdrawal) {
            throw ProviderApiException::notFound('Withdrawal not found.');
        }

        return $this->ok($this->describe($withdrawal) + [
            'payout_reference' => $withdrawal->status === Withdrawal::PAID ? $withdrawal->payout_reference : null,
            'created_at' => $withdrawal->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'processed_at' => $withdrawal->processed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return array<string, mixed> */
    private function describe(Withdrawal $w): array
    {
        return [
            'id' => $w->id, 'amount' => $w->amount, 'fee' => $w->fee, 'net_amount' => $w->net_amount, 'status' => $w->status,
            // The reason an admin gave when it did not go through.
            'failure_reason' => in_array($w->status, [Withdrawal::FAILED, Withdrawal::REJECTED], true) ? $w->status_reason : null,
        ];
    }
}
