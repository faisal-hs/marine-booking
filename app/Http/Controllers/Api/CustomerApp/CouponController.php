<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\ValidateCouponRequest;
use App\Models\Coupon;
use App\Services\CouponService;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\CustomerApp\Checkout;
use App\Support\Money\CheckoutTotals;
use Illuminate\Validation\ValidationException;

/**
 * A coupon, checked for an activity before it is used. The answer is the same shape as the `coupon` of the booking summary (`code`, `is_valid`, `message`) with what the coupon is
 * and, when the booking is described (or an amount is sent), what it takes off. A coupon that cannot be used is a normal answer (`is_valid` false, and why), not an error: an
 * unknown code and a switched-off one are answered alike, so the answer never tells which codes exist.
 */
class CouponController extends CustomerAppController
{
    public function __construct(
        private readonly ActivityCatalog $catalog,
        private readonly Checkout $checkout,
        private readonly CouponService $coupons,
    ) {}

    public function validate(ValidateCouponRequest $request, string $id)
    {
        $activity = $this->catalog->find($id) ?? throw ProviderApiException::notFound('Activity not found.');
        $code = (string) $request->input('coupon_code');
        $subtotal = null;

        if ($request->filled('date')) {
            // The booking itself is priced as the summary prices it (a departure that is not available, or details the option does not take, are refused as there).
            $subtotal = (float) $this->checkout->summary($activity, collect($request->validated())->except(['coupon_code', 'amount'])->all())['subtotal'];
        } elseif ($request->filled('amount')) {
            $subtotal = (float) $request->input('amount');
        }

        try {
            ['coupon' => $coupon, 'discount' => $discount] = $this->coupons->resolve($activity, $code, $subtotal);
        } catch (ValidationException $e) {
            return $this->ok([
                'code' => CouponService::normalize($code),
                'is_valid' => false,
                'message' => $e->errors()['coupon_code'][0] ?? 'This coupon cannot be used.',
                'type' => null,
                'value' => null,
                'max_discount' => null,
                'min_order_amount' => null,
                'currency' => config('platform.currency'),
                'subtotal' => $subtotal,
                'discount' => null,
                'service_fee' => null,
                'vat' => null,
                'total' => null,
            ]);
        }

        $totals = $discount === null ? null : CheckoutTotals::compute($subtotal, $discount);

        return $this->ok([
            'code' => $coupon->code,
            'is_valid' => true,
            'message' => $this->checkout->appliedMessage($coupon, $discount),
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'max_discount' => $coupon->max_discount !== null ? (float) $coupon->max_discount : null,
            'min_order_amount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
            'currency' => config('platform.currency'),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'service_fee' => $totals['service_fee'] ?? null,
            'vat' => $totals['vat'] ?? null,
            'total' => $totals['total'] ?? null,
        ]);
    }
}
