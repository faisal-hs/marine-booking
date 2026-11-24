<?php

namespace App\Services\Payments\Concerns;

use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * What the online gateways share: one way to make a call (timeouts, and a failure that is told apart from a refusal), one way to log (never a key, a token, or a customer's
 * details), and the signed addresses the customer comes back to.
 */
trait TalksToGateways
{
    /** @var array<string, mixed> */
    protected array $config;

    protected function client(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->timeout((int) ($this->config['timeout'] ?? 15))->connectTimeout(5);
    }

    /**
     * Makes a call. A connection that fails, a timeout, a 5xx and a 429 are the gateway being unreachable (`$failure` is what is thrown: for the call that starts a payment nothing was
     * sent that matters, for the others it is a PaymentGatewayException). Any other answer, 4xx included, is returned for the caller to read: a refusal is information.
     *
     * @param  callable(): PendingRequest  $request
     * @param  class-string<\Throwable>  $failure
     */
    protected function send(string $operation, string $method, string $url, callable $request, ?array $body = null, string $failure = PaymentGatewayException::class): Response
    {
        try {
            $client = $request();
            $response = $method === 'GET' ? $client->retry(2, 250, fn ($e) => $e instanceof ConnectionException, throw: false)->get($url) : $client->send($method, $url, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            $this->log('error', "{$operation}: the gateway could not be reached", ['reason' => class_basename($e)]);

            throw $this->unreachable($failure, "{$this->name()} {$operation}: the gateway could not be reached", $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            $this->log('error', "{$operation}: the gateway answered {$response->status()}");

            throw $this->unreachable($failure, "{$this->name()} {$operation}: the gateway answered {$response->status()}");
        }

        return $response;
    }

    private function unreachable(string $failure, string $message, ?\Throwable $previous = null): \Throwable
    {
        return $failure === PaymentGatewayUnavailableException::class ? new PaymentGatewayUnavailableException($previous, $message) : new PaymentGatewayException($message, $previous);
    }

    /** One line in the payments log, with ids and states only. */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel(config('payments.log_channel'))->{$level}("[{$this->name()}] {$message}", $context);
    }

    /** A message from a refusal, short enough to log and never more than the gateway's own words. */
    protected function reasonFrom(Response $response): string
    {
        $json = $response->json();
        $text = is_array($json) ? ($json['message'] ?? $json['error'] ?? $json['errors'] ?? $json) : (string) $response->body();

        return mb_substr(is_string($text) ? $text : json_encode($text), 0, 200);
    }

    /** Where the gateway sends the customer: OUR return endpoint (signed, so it cannot be made up), which then sends them to the app. */
    protected function returnUrl(Payment $payment, string $outcome): string
    {
        return URL::temporarySignedRoute('payments.return', now()->addDays(3), ['payment' => $payment->uuid, 'outcome' => $outcome]);
    }

    protected function webhookUrl(): string
    {
        return route('webhooks.payments', ['gateway' => $this->name()]);
    }

    /** The name and phone to give the gateway: what the customer said when booking, else their account. */
    protected function buyerOf(Payment $payment): array
    {
        $reservation = $payment->reservation;
        $user = $reservation->customer?->user;

        return [
            'name' => trim((string) ($reservation->contact_name ?: $user?->name)) ?: 'Customer',
            'phone' => (string) ($reservation->contact_phone ?: $user?->phone),
            'email' => (string) ($reservation->contact_email ?: $user?->email),
        ];
    }

    /** An international number: "05..." with the country's calling code, "+966 5..." kept as it is without spaces. */
    protected function internationalPhone(string $phone, string $country = 'SA'): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $codes = ['SA' => '966', 'AE' => '971', 'KW' => '965', 'BH' => '973', 'QA' => '974', 'OM' => '968'];

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        return '+'.($codes[strtoupper($country)] ?? '966').ltrim($digits, '0');
    }
}
