<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Deliberately public (no auth middleware) — a gateway calling this has
 * no session/token of ours to present. Trust comes entirely from
 * PaymentGatewayInterface::handleWebhook()'s own signature verification
 * inside the bound driver, never from anything at the route/Controller
 * level. A ValidationException from an invalid signature or unknown
 * reference propagates naturally and renders as JSON 422, matching
 * every other Controller in this app — no local catch/rewrap here.
 */
class PaymentWebhookController extends BaseApiController
{
    public function __construct(protected PaymentService $service) {}

    public function handle(Request $request, string $gateway)
    {
        abort_if($gateway !== config('payments.default'), 404);

        $payment = $this->service->handleWebhook($request);

        return $this->success(['status' => $payment->status->value]);
    }
}
