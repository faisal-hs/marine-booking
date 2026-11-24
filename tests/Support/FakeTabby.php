<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stateful stand-in for Tabby's HTTP API (https://docs.tabby.ai), for tests: it creates sessions and payments, lets a test approve, reject or expire a payment as the customer would, and
 * enforces the rules the real one has (a payment can only be captured after it is AUTHORIZED, only a captured payment can be refunded, a payment can only be closed before it is captured).
 * Every call is recorded in `$calls`. It is a fake of Tabby as documented: it proves OUR side of the conversation, not that Tabby's real servers agree.
 *
 * `$mode`: ok | reject (the session is refused: `rejected`) | down (every call is a 503) | capture_down (only the capture call is a 503) | capture_refused (the capture is a 400).
 */
class FakeTabby
{
    /** @var array<string, array<string, mixed>> */
    public array $payments = [];

    /** @var list<array{method: string, path: string, body: array, auth: string}> */
    public array $calls = [];

    public string $mode = 'ok';

    private int $n = 0;

    public static function install(): self
    {
        $fake = new self;
        // A call that is not Tabby's is left to the next stand-in (a stub that answers null is skipped), so Tabby's and Tamara's can be installed together.
        Http::fake(fn (Request $request) => str_contains($request->url(), 'tabby') ? $fake->handle($request) : null);

        return $fake;
    }

    public function handle(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $body = $request->data();
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $body, 'auth' => (string) $request->header('Authorization')[0]];

        if ($this->mode === 'down' || ($this->mode === 'capture_down' && str_ends_with($path, '/captures'))) {
            return Http::response(['error' => 'unavailable'], 503);
        }

        if ($request->method() === 'POST' && $path === '/api/v2/checkout') {
            return $this->checkout($body);
        }

        if (preg_match('#^/api/v2/payments/([^/]+)(?:/(captures|close|refunds))?$#', $path, $m)) {
            $payment = &$this->payments[$m[1]];

            if (! isset($payment)) {
                return Http::response(['error' => 'not found'], 404);
            }

            return match ($m[2] ?? null) {
                null => Http::response($this->view($payment)),
                'captures' => $this->capture($payment, $body),
                'close' => $this->close($payment),
                'refunds' => $this->refund($payment, $body),
            };
        }

        return Http::response(['error' => 'unknown call'], 404);
    }

    private function checkout(array $body)
    {
        if ($this->mode === 'reject') {
            return Http::response(['id' => 'sess_rejected', 'status' => 'rejected', 'configuration' => ['products' => ['installments' => ['rejection_reason' => 'order_amount_too_high']]]]);
        }

        $id = 'tabby_pay_'.(++$this->n);
        $this->payments[$id] = [
            'id' => $id, 'status' => 'CREATED', 'amount' => $body['payment']['amount'], 'currency' => $body['payment']['currency'], 'order' => ['reference_id' => $body['payment']['order']['reference_id']],
            'captures' => [], 'refunds' => [], 'created_with' => $body,
        ];

        return Http::response([
            'id' => 'sess_'.$this->n, 'status' => 'created',
            'payment' => ['id' => $id, 'status' => 'CREATED', 'expires_at' => now()->addHour()->toIso8601String()],
            'configuration' => ['available_products' => ['installments' => [['web_url' => "https://checkout.tabby.test/?sessionId=sess_{$this->n}"]]]],
        ]);
    }

    private function capture(array &$payment, array $body)
    {
        if ($this->mode === 'capture_refused' || $payment['status'] !== 'AUTHORIZED') {
            return Http::response(['error' => 'cannot capture'], 400);
        }

        $payment['status'] = 'CLOSED';
        $payment['captures'][] = ['id' => 'cap_'.count($payment['captures']), 'amount' => $body['amount']];

        return Http::response($this->view($payment));
    }

    private function close(array &$payment)
    {
        if ($payment['status'] !== 'AUTHORIZED') {
            return Http::response(['error' => 'cannot close'], 400);
        }

        $payment['status'] = 'CLOSED';

        return Http::response($this->view($payment));
    }

    private function refund(array &$payment, array $body)
    {
        $captured = array_sum(array_column($payment['captures'], 'amount'));
        $refunded = array_sum(array_column($payment['refunds'], 'amount'));

        if ($captured <= 0 || (float) $body['amount'] + $refunded > $captured + 0.001) {
            return Http::response(['error' => 'cannot refund that'], 400);
        }

        $payment['refunds'][] = ['id' => 'ref_'.count($payment['refunds']), 'amount' => $body['amount'], 'reference_id' => $body['reference_id'] ?? null];

        return Http::response($this->view($payment));
    }

    private function view(array $payment): array
    {
        return array_diff_key($payment, ['created_with' => 1]);
    }

    // --- What the customer does at Tabby's page ----------------------------------------------------------------------------------

    public function approve(string $id): void
    {
        $this->payments[$id]['status'] = 'AUTHORIZED';
    }

    public function reject(string $id): void
    {
        $this->payments[$id]['status'] = 'REJECTED';
    }

    public function expire(string $id): void
    {
        $this->payments[$id]['status'] = 'EXPIRED';
    }

    /** The payment id of the n-th session created (1-based). */
    public function id(int $n = 1): string
    {
        return 'tabby_pay_'.$n;
    }

    /** @return list<array{method: string, path: string, body: array, auth: string}> the calls whose path ends with `$suffix` */
    public function calls(string $suffix): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_ends_with($c['path'], $suffix)));
    }
}
