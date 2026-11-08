<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coupons
    |--------------------------------------------------------------------------
    |
    | A coupon is funded by the provider that made it; the platform funds none. However large the discount, a booking never costs less than this
    | (in the platform currency): a discount that would go below it is cut back, so a payment is never for nothing.
    |
    */
    'minimum_total' => (float) env('COUPON_MINIMUM_TOTAL', 1),
];
