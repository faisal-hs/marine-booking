<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Where an online gateway (Tabby, Tamara) sends the customer after they pay, cancel or fail: `/payments/{payment}/return/{success|cancel|failure}`. The address is SIGNED (so it
 * cannot be made up for someone else's payment); the gateway adds its own parameters to it, which are ignored. What the customer says by landing here is not believed: the payment
 * is asked of the gateway (PaymentService::handleReturn), and then the customer is sent on to the app (`payments.return_urls`) with ?booking=&payment=&status= (paid, pending,
 * failed, cancelled, expired). With no URL set it answers JSON, which is what a test or a web checkout wants.
 */
class PaymentReturnController extends BaseApiController
{
    private const GATEWAY_PARAMETERS = ['orderId', 'paymentStatus', 'payment_id', 'paymentId', 'status', 'tamaraToken'];

    public function __construct(protected PaymentService $service) {}

    public function handle(Request $request, string $payment, string $outcome)
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::GATEWAY_PARAMETERS), 403, 'This link is not valid or has expired.');

        $payment = Payment::where('uuid', $payment)->firstOrFail();

        try {
            $payment = $this->service->handleReturn($payment, $outcome);
        } catch (PaymentGatewayException) {
            // The gateway could not be asked just now. The customer is told it is still being confirmed: the notification, or the reconciler, will settle it.
            $payment->refresh();
        }

        $status = $this->statusOf($payment);
        $target = config('payments.return_urls.'.match ($status) {'paid', 'pending' => 'success', 'cancelled' => 'cancel', default => 'failure'});
        $query = http_build_query(['booking' => $payment->reservation->uuid, 'payment' => $payment->reference_id, 'status' => $status]);

        if ($target) {
            return redirect()->away($target.(str_contains($target, '?') ? '&' : '?').$query);
        }

        return $this->success(['booking' => $payment->reservation->uuid, 'payment' => $payment->reference_id, 'status' => $status, 'reason' => $payment->failure_reason]);
    }

    private function statusOf(Payment $payment): string
    {
        return match (true) {
            $payment->status === PaymentStatus::Succeeded => 'paid',
            $payment->status === PaymentStatus::Pending => 'pending',
            $payment->failure_code === 'cancelled' => 'cancelled',
            $payment->failure_code === 'expired' => 'expired',
            default => 'failed',
        };
    }
}
