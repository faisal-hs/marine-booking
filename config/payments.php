<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | Only 'mock' exists today, and it is refused in production because it
    | charges nobody. A real gateway (Stripe, Paymob, etc.) is added by:
    | (1) implementing PaymentGatewayInterface, (2) adding an arm for it to
    | the match in PaymentServiceProvider, (3) changing this value — nothing
    | in PaymentService or the Controllers needs to change. A value with no
    | arm is refused rather than falling back to the mock.
    |
    */
    'default' => env('PAYMENT_GATEWAY', 'mock'),

    'mock' => [
        'webhook_secret' => env('MOCK_PAYMENT_WEBHOOK_SECRET', 'mock-webhook-secret-for-local-dev-only'),
    ],
];
