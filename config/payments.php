<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | Only 'mock' exists today. A real gateway (Stripe, Paymob, etc.) is
    | added by: (1) implementing PaymentGatewayInterface, (2) binding it
    | in PaymentServiceProvider, (3) changing this value — nothing in
    | PaymentService or the Controllers needs to change.
    |
    */
    'default' => env('PAYMENT_GATEWAY', 'mock'),

    'mock' => [
        'webhook_secret' => env('MOCK_PAYMENT_WEBHOOK_SECRET', 'mock-webhook-secret-for-local-dev-only'),
    ],
];
