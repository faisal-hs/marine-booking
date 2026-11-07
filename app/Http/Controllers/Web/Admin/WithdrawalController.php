<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Withdrawal\MarkWithdrawalPaidRequest;
use App\Http\Requests\Web\Admin\Withdrawal\WithdrawalReasonRequest;
use App\Models\Withdrawal;
use App\Services\Wallet\WithdrawalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Providers ask to be paid; an admin pays by hand (the system moves no money) and records what happened here. */
class WithdrawalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Withdrawal::class);

        // Waiting requests first: that is what an admin opens this screen for.
        $status = $request->input('status', 'pending');
        $search = trim((string) $request->input('search', ''));

        $query = Withdrawal::query()->with('provider')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('provider', fn ($p) => $p->where('business_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%')))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END")->orderByDesc('id');

        $counts = Withdrawal::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();
        $counts['all'] = array_sum($counts);

        return view('admin.withdrawals.index', ['withdrawals' => $query->paginate(20)->withQueryString(), 'status' => $status, 'search' => $search, 'counts' => $counts]);
    }

    public function show(Withdrawal $withdrawal): View
    {
        $this->authorize('view', $withdrawal);

        return view('admin.withdrawals.show', ['withdrawal' => $withdrawal->load(['provider.user', 'processor'])]);
    }

    public function processing(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        return $this->act($withdrawal, 'started', fn () => $this->withdrawals->startProcessing($withdrawal, $request->user()));
    }

    public function paid(MarkWithdrawalPaidRequest $request, Withdrawal $withdrawal): RedirectResponse
    {
        return $this->act($withdrawal, 'paid', fn () => $this->withdrawals->markPaid($withdrawal, $request->user(), $request->validated('payout_reference')));
    }

    public function reject(WithdrawalReasonRequest $request, Withdrawal $withdrawal): RedirectResponse
    {
        return $this->act($withdrawal, 'rejected', fn () => $this->withdrawals->reject($withdrawal, $request->user(), $request->validated('reason')));
    }

    public function fail(WithdrawalReasonRequest $request, Withdrawal $withdrawal): RedirectResponse
    {
        return $this->act($withdrawal, 'failed', fn () => $this->withdrawals->fail($withdrawal, $request->user(), $request->validated('reason')));
    }

    private function act(Withdrawal $withdrawal, string $done, callable $action): RedirectResponse
    {
        $this->authorize('process', $withdrawal);

        try {
            $action();
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('status', __('admin.withdrawals.done.'.$done));
    }
}
