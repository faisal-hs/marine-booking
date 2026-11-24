<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * Deliberately public (no auth middleware) — a gateway calling this has
 * no session/token of ours to present. Trust comes entirely from
 * PaymentGatewayInterface::handleWebhook()'s own verification inside the
 * gateway's driver (Tabby: our secret header; Tamara: the signed token),
 * never from anything at the route/Controller level. A notification that
 * does not prove itself is a 401 (InvalidWebhookException); an unknown
 * reference is a 422, as it always was.
 *
 * `{gateway}` says which gateway is calling: the default one, or an
 * enabled online one (tabby, tamara). Anything else is a 404.
 */
class PaymentWebhookController extends BaseApiController
{
    public function __construct(protected PaymentService $service, protected PaymentGatewayManager $gateways) {}

    public function handle(Request $request, string $gateway)
    {
        abort_unless($this->gateways->acceptsWebhooksFor($gateway), 404);

        $payment = $this->service->handleWebhook($request, $gateway);

        return $this->success(['status' => $payment->status->value]);
    }
}
