<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The customer app: what a booking costs on top of the activity's price
    |--------------------------------------------------------------------------
    |
    | service_fee_percent: the platform's fee, a percentage of the activity's price BEFORE any coupon (the coupon is the provider's gift to the customer,
    |                      not a cut in the platform's fee). It is the platform's income only: it is not part of what the provider earns.
    | vat_percent:         VAT on the price after the coupon plus the service fee. It goes to the tax authority: it is not part of what the provider earns.
    |
    | Both are 0 until the business confirms the formula (the specification's example uses 3 and 15: 900 - 180 + 27 + 112.05).
    |
    */
    'service_fee_percent' => (float) env('CUSTOMER_SERVICE_FEE_PERCENT', 0),
    'vat_percent' => (float) env('CUSTOMER_VAT_PERCENT', 0),

    /*
    |--------------------------------------------------------------------------
    | Customer cancellation: how much of what was paid comes back
    |--------------------------------------------------------------------------
    |
    | More than full_refund_hours before the trip: everything. Then more than partial_refund_hours: partial_refund_percent of it. Closer than that:
    | nothing. (A provider cancelling always refunds everything: that is a different rule, in the provider app.)
    |
    */
    'cancellation' => [
        'full_refund_hours' => (int) env('CANCELLATION_FULL_REFUND_HOURS', 24),
        'partial_refund_hours' => (int) env('CANCELLATION_PARTIAL_REFUND_HOURS', 12),
        'partial_refund_percent' => (float) env('CANCELLATION_PARTIAL_REFUND_PERCENT', 50),
    ],

    // An activity with at least this many bookings carries the "bestseller" badge.
    'bestseller_min_bookings' => (int) env('BESTSELLER_MIN_BOOKINGS', 25),

    // The date the terms, the privacy policy and the cancellation page were last changed (shown on those pages).
    'content_updated_at' => env('CONTENT_UPDATED_AT', '2026-11-01'),

    // How many activities the home screen shows in each list.
    'home_limit' => 10,

    // The messages in a page of a conversation.
    'messages_per_page' => 30,

    // What a customer can write to support about.
    'support_topics' => ['booking', 'payment', 'account', 'activity', 'other'],

    // The public support contact details (empty ones are shown as null).
    'support' => [
        'phone' => env('SUPPORT_PHONE'),
        'email' => env('SUPPORT_EMAIL'),
        'whatsapp' => env('SUPPORT_WHATSAPP'),
    ],

    // The "about" page. The counts are worked out from the data; the year and the app versions are settings.
    'about' => [
        'founded_year' => (int) env('PLATFORM_FOUNDED_YEAR', 2023),
        'app_version' => [
            'ios' => ['latest' => env('APP_VERSION_IOS', '1.0.0'), 'minimum' => env('APP_MIN_VERSION_IOS', '1.0.0'), 'store_url' => env('APP_STORE_URL_IOS')],
            'android' => ['latest' => env('APP_VERSION_ANDROID', '1.0.0'), 'minimum' => env('APP_MIN_VERSION_ANDROID', '1.0.0'), 'store_url' => env('APP_STORE_URL_ANDROID')],
        ],
    ],
];
