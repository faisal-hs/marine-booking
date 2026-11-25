<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\Payment;
use App\Services\CustomerApp\BookingPresenter;
use App\Services\CustomerApp\Checkout;
use App\Services\PaymentService;
use App\Support\Money\GatewayAmount;
use Illuminate\Http\Request;

/**
 * Where an online gateway (Tabby, Tamara) sends the customer after they pay, cancel or fail: `/payments/{payment}/return/{success|cancel|failure}`. The address is SIGNED (so it
 * cannot be made up for someone else's payment); the gateway adds its own parameters to it, which are ignored. What the customer says by landing here is not believed: the payment
 * is asked of the gateway (PaymentService::handleReturn). Then, in this order:
 *
 *   1. when `payments.return_urls.*` has an address (an app deep link, a web page), the customer is sent there with ?booking=&payment=&status=;
 *   2. a client that asks for JSON (`Accept: application/json`, or ?format=json) gets {booking, payment, status, reason} and the amount and references;
 *   3. anyone else (the customer's browser) gets a PAGE: the status, the amount and the reference numbers (resources/views/payments/return.blade.php), in English or Arabic
 *      (`lang` is signed into the address when it is made, so the page speaks the language of the app; else the browser's).
 *
 * Statuses: paid, pending, failed, cancelled, expired.
 */
class PaymentReturnController extends BaseApiController
{
    private const GATEWAY_PARAMETERS = ['orderId', 'paymentStatus', 'payment_id', 'paymentId', 'status', 'tamaraToken'];

    public function __construct(protected PaymentService $service, protected BookingPresenter $bookings) {}

    public function handle(Request $request, string $payment, string $outcome)
    {
        abort_unless($request->hasValidSignatureWhileIgnoring(self::GATEWAY_PARAMETERS), 403, 'This link is not valid or has expired.');

        $payment = Payment::where('uuid', $payment)->firstOrFail();
        app()->setLocale($this->locale($request));

        try {
            $payment = $this->service->handleReturn($payment, $outcome);
        } catch (PaymentGatewayException) {
            // The gateway could not be asked just now. The customer is told it is still being confirmed: the notification, or the reconciler, will settle it.
            $payment->refresh();
        }

        $payment->loadMissing('reservation.activity');
        $status = $this->statusOf($payment);
        $reservation = $payment->reservation;
        $target = config('payments.return_urls.'.match ($status) {'paid', 'pending' => 'success', 'cancelled' => 'cancel', default => 'failure'});

        if ($target) {
            return redirect()->away($target.(str_contains($target, '?') ? '&' : '?').http_build_query(['booking' => $reservation->uuid, 'payment' => $payment->reference_id, 'status' => $status]));
        }

        $currency = strtoupper((string) $payment->currency);

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return $this->success([
                'booking' => $reservation->uuid,
                'payment' => $payment->reference_id,
                'status' => $status,
                'reason' => $payment->failure_reason,
                'amount' => (float) $payment->amount,
                'currency' => $currency,
                'booking_reference' => $this->bookings->reference($reservation),
                'paid_at' => $status === 'paid' ? $payment->paid_at?->utc()->format('Y-m-d\TH:i:s\Z') : null,
            ]);
        }

        $decimals = GatewayAmount::decimals($currency);
        $refunded = (float) $payment->refunded_amount;

        return response()->view('payments.return', [
            'status' => $status,
            'amount' => number_format((float) $payment->amount, $decimals),
            'currency' => $currency,
            'paymentReference' => $payment->reference_id,
            'bookingReference' => $this->bookings->reference($reservation),
            'activity' => $reservation->activity?->title,
            'when' => $this->when($reservation),
            'method' => $this->method($payment),
            'paidAt' => $payment->paid_at?->timezone(Checkout::tz())->format('Y-m-d H:i'),
            'refunded' => $refunded > 0 ? number_format($refunded, $decimals) : null,
            'reasonText' => $status === 'failed' && trans()->has("payments.return.reasons.{$payment->failure_code}") ? __("payments.return.reasons.{$payment->failure_code}") : null,
            'refresh' => $status === 'pending',
            'appUrl' => config('payments.return_urls.app'),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** The page's language: the one signed into the address (`lang`), else the browser's, else the platform's. */
    private function locale(Request $request): string
    {
        $asked = (string) $request->query('lang', '');

        return in_array($asked, ['en', 'ar'], true) ? $asked : ($request->getPreferredLanguage(['en', 'ar']) ?? (string) config('app.locale'));
    }

    /** When the trip is: the day, and for a booking by time the range, in the platform's own time. */
    private function when($reservation): string
    {
        if ($reservation->starts_at && $reservation->ends_at) {
            $from = $reservation->starts_at->copy()->timezone(Checkout::tz());
            $to = $reservation->ends_at->copy()->timezone(Checkout::tz());

            return $from->format('Y-m-d H:i').' – '.($from->isSameDay($to) ? $to->format('H:i') : $to->format('Y-m-d H:i'));
        }

        return $reservation->reservation_date->toDateString();
    }

    private function method(Payment $payment): string
    {
        $method = (string) ($payment->reservation->payment_method ?: $payment->gateway);

        return ucfirst(str_replace('_', ' ', $method));
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
