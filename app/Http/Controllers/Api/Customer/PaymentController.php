<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\Payment\InitiatePaymentRequest;
use App\Http\Resources\Payment\PaymentResource;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends BaseApiController
{
    public function __construct(protected PaymentService $service) {}

    public function store(InitiatePaymentRequest $request, Reservation $reservation)
    {
        abort_if($reservation->customer_id !== $request->user()->customer->id, 404);

        $result = $this->service->initiate($reservation, $request->params());

        return $this->created([
            'payment' => new PaymentResource($result['payment']),
            'redirect_url' => $result['redirect_url'],
        ], 'Payment initiated.');
    }

    public function show(Request $request, Payment $payment)
    {
        $payment->load('reservation');

        abort_if($payment->reservation->customer_id !== $request->user()->customer->id, 404);

        return $this->success(new PaymentResource($payment));
    }
}
