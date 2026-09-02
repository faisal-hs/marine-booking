<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Admin\Payment\AdminPaymentIndexRequest;
use App\Http\Resources\Payment\AdminPaymentResource;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Read-only, matching Api\Admin\ReservationController exactly — no
 * store/update/destroy. Refunds go through the DEDICATED endpoint below
 * (a genuine mutation, explicitly allowed per the finalized Module 10
 * plan, unlike Module 09's admin reservation surface which stayed
 * strictly read-only end to end) — it still delegates entirely to
 * PaymentService::refund(), never touching gateway or Eloquent logic
 * directly.
 */
class PaymentController extends BaseApiController
{
    use AuthorizesRequests;

    public function __construct(
        protected PaymentRepositoryInterface $repository,
        protected \App\Services\PaymentService $service,
    ) {}

    public function index(AdminPaymentIndexRequest $request)
    {
        $this->authorize('viewAny', Payment::class);

        $payments = $this->repository->paginateForAdmin($request->filters(), $request->perPage());

        return $this->success(AdminPaymentResource::collection($payments));
    }

    public function show(Payment $payment)
    {
        $this->authorize('view', $payment);

        $payment->load(['reservation.customer.user', 'reservation.activity']);

        return $this->success(new AdminPaymentResource($payment));
    }

    public function refund(Payment $payment)
    {
        $this->authorize('refund', $payment);

        $amount = request()->float('amount') ?: null;

        $payment = $this->service->refund($payment, $amount);

        $payment->load(['reservation.customer.user', 'reservation.activity']);

        return $this->success(new AdminPaymentResource($payment), 'Payment refunded successfully.');
    }
}
