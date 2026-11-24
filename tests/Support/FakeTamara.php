<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stateful stand-in for Tamara's HTTP API (https://docs.tamara.co), for tests, in the way FakeTabby is one for Tabby: orders move new -> approved (the customer) -> authorised
 * (us) -> fully_captured (us); a capture needs an authorised order, a cancel needs an authorised one, a refund needs a captured one. Every call is recorded in `$calls`.
 *
 * `$mode`: ok | down (every call is a 503) | checkout_refused (the checkout is a 400) | capture_down (only the capture call is a 503).
 */
class FakeTamara
{
    /** @var array<string, array<string, mixed>> keyed by order id */
    public array $orders = [];

    /** @var list<array{method: string, path: string, body: array, auth: string}> */
    public array $calls = [];

    public string $mode = 'ok';

    private int $n = 0;

    public static function install(): self
    {
        $fake = new self;
        // A call that is not Tamara's is left to the next stand-in (a stub that answers null is skipped), so Tabby's and Tamara's can be installed together.
        Http::fake(fn (Request $request) => str_contains($request->url(), 'tamara') ? $fake->handle($request) : null);

        return $fake;
    }

    public function handle(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $body = $request->data();
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $body, 'auth' => (string) $request->header('Authorization')[0]];

        if ($this->mode === 'down' || ($this->mode === 'capture_down' && $path === '/payments/capture')) {
            return Http::response(['message' => 'unavailable'], 503);
        }

        if ($request->method() === 'POST' && $path === '/checkout') {
            return $this->checkout($body);
        }

        if (preg_match('#^/merchants/orders/reference-id/(.+)$#', $path, $m)) {
            $order = $this->byReference(rawurldecode($m[1]));

            return $order ? Http::response($this->view($order)) : Http::response(['message' => 'not found'], 404);
        }

        if (preg_match('#^/orders/([^/]+)/(authorise|cancel)$#', $path, $m)) {
            return $this->transition($m[1], $m[2]);
        }

        if ($path === '/payments/capture') {
            return $this->capture($body);
        }

        if (preg_match('#^/payments/simplified-refund/([^/]+)$#', $path, $m)) {
            return $this->refund($m[1], $body);
        }

        return Http::response(['message' => 'unknown call'], 404);
    }

    private function checkout(array $body)
    {
        if ($this->mode === 'checkout_refused') {
            return Http::response(['message' => 'Invalid request', 'errors' => [['error_code' => 'total_amount_invalid_limit']]], 400);
        }

        $id = 'tamara-order-'.(++$this->n);
        $this->orders[$id] = ['order_id' => $id, 'order_reference_id' => $body['order_reference_id'], 'status' => 'new', 'total_amount' => $body['total_amount'], 'captured' => 0, 'refunded' => 0, 'created_with' => $body];

        return Http::response(['order_id' => $id, 'checkout_id' => 'chk-'.$this->n, 'checkout_url' => "https://checkout.tamara.test/checkout/chk-{$this->n}", 'status' => 'new']);
    }

    private function transition(string $id, string $what)
    {
        $order = &$this->orders[$id];

        if (! isset($order)) {
            return Http::response(['message' => 'not found'], 404);
        }

        if ($what === 'authorise') {
            if ($order['status'] !== 'approved') {
                return Http::response(['message' => 'order is not approved'], 409);
            }
            $order['status'] = 'authorised';

            return Http::response(['order_id' => $id, 'status' => 'authorised']);
        }

        if ($order['status'] !== 'authorised') {
            return Http::response(['message' => 'order cannot be cancelled'], 409);
        }
        $order['status'] = 'canceled';

        return Http::response(['order_id' => $id, 'status' => 'canceled']);
    }

    private function capture(array $body)
    {
        $order = &$this->orders[$body['order_id'] ?? ''];

        if (! isset($order) || $order['status'] !== 'authorised') {
            return Http::response(['message' => 'order is not authorised'], 409);
        }

        $order['status'] = 'fully_captured';
        $order['captured'] = $body['total_amount']['amount'];

        return Http::response(['order_id' => $order['order_id'], 'capture_id' => 'cap-'.$order['order_id'], 'status' => 'fully_captured']);
    }

    private function refund(string $id, array $body)
    {
        $order = &$this->orders[$id];

        if (! isset($order) || $order['captured'] <= 0 || (float) $body['total_amount']['amount'] + $order['refunded'] > $order['captured'] + 0.001) {
            return Http::response(['message' => 'cannot refund that'], 409);
        }

        $order['refunded'] += (float) $body['total_amount']['amount'];

        return Http::response(['order_id' => $id, 'refund_id' => 'ref-'.$id.'-'.$order['refunded'], 'status' => 'partially_refunded']);
    }

    private function byReference(string $reference): ?array
    {
        foreach ($this->orders as $order) {
            if ($order['order_reference_id'] === $reference) {
                return $order;
            }
        }

        return null;
    }

    private function view(array $order): array
    {
        return array_diff_key($order, ['created_with' => 1, 'captured' => 1, 'refunded' => 1]);
    }

    // --- What the customer does at Tamara's page ---------------------------------------------------------------------------------

    public function approve(string $id): void
    {
        $this->orders[$id]['status'] = 'approved';
    }

    public function decline(string $id): void
    {
        $this->orders[$id]['status'] = 'declined';
    }

    public function expire(string $id): void
    {
        $this->orders[$id]['status'] = 'expired';
    }

    public function id(int $n = 1): string
    {
        return 'tamara-order-'.$n;
    }

    /** @return list<array{method: string, path: string, body: array, auth: string}> */
    public function calls(string $path): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['path'] === $path || str_ends_with($c['path'], $path)));
    }
}
