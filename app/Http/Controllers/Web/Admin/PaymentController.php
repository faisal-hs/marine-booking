<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Index/show are read-only, matching Web\Admin\ReservationController.
 * refund() is a genuine mutation — deliberately gated by the SEPARATE
 * 'payments.refund' permission (not 'payments.view'), and delegates
 * entirely to PaymentService::refund(), never touching gateway or
 * Eloquent logic directly.
 */
class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected PaymentRepositoryInterface $repository,
        protected PaymentService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->only(['status', 'gateway', 'search', 'sort']);
        $payments = $this->repository->paginateForAdmin($filters);

        return view('admin.payments.index', ['payments' => $payments, 'filters' => $filters]);
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);

        $payment->load(['reservation.customer.user', 'reservation.activity']);

        return view('admin.payments.show', ['payment' => $payment]);
    }

    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('refund', $payment);

        $amount = $request->filled('amount') ? (float) $request->input('amount') : null;

        try {
            $this->service->refund($payment, $amount);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', __('admin.payments.refunded'));
    }
}
