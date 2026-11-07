<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider app API
    |--------------------------------------------------------------------------
    |
    | Settings for the endpoints written to the "Provider platform API specification" (routes/api_provider.php).
    |
    */

    // Paging: the spec asks for page and per_page, with a maximum of 100.
    'per_page_default' => 20,
    'per_page_max' => 100,

    /*
    | Customer tiers (GET /provider/customers). A customer's score out of 100 is made of four parts, each earned in proportion
    | up to a cap, so a customer who reaches every cap scores 100:
    |
    |   spend             completed spend with this provider            up to `spend` EGP        -> 40 points
    |   bookings          completed bookings with this provider         up to `bookings`         -> 30 points
    |   recent            bookings made in the last `recent_days` days  up to `recent`           -> 20 points
    |   no_cancellations  10 points if they cancelled nothing in the last `cancellation_days` days, else 0
    |
    | Tier: vip at or above `vip`, regular at or above `regular`, otherwise normal. The weights and thresholds are the spec's
    | suggestion; the CAPS are not in the spec (it gives no scale) and, like the thresholds, need business approval.
    */
    'customer_tiers' => [
        'weights' => ['spend' => 40, 'bookings' => 30, 'recent' => 20, 'no_cancellations' => 10],
        'caps' => ['spend' => 5000, 'bookings' => 10, 'recent' => 3],
        'recent_days' => 30,
        'cancellation_days' => 90,
        'thresholds' => ['vip' => 70, 'regular' => 30],
    ],
];
