<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    |
    | The gateway for every payment method that has no gateway of its own (card,
    | apple_pay, mada). Only 'mock' exists for those today, and it is refused in
    | production because it charges nobody: a card gateway is added by
    | implementing PaymentGatewayInterface and giving it an arm in
    | PaymentGatewayManager. A value with no arm is refused rather than falling
    | back to the mock.
    |
    | TABBY and TAMARA do not use this: they are payment methods with a gateway
    | each (see `methods` and `gateways` below), chosen per payment.
    |
    */
    'default' => env('PAYMENT_GATEWAY', 'mock'),

    'mock' => [
        'webhook_secret' => env('MOCK_PAYMENT_WEBHOOK_SECRET', 'mock-webhook-secret-for-local-dev-only'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment methods that have a gateway of their own
    |--------------------------------------------------------------------------
    |
    | payment_method => gateway. A customer who pays with `tabby` pays through the
    | Tabby gateway, whatever PAYMENT_GATEWAY says. Any other method goes to the
    | default gateway.
    |
    */
    'methods' => [
        'tabby' => 'tabby',
        'tamara' => 'tamara',
    ],

    'gateways' => [

        /*
        | Tabby (https://docs.tabby.ai). One base URL for test and live: which one a call
        | is for is decided by the KEY (pk_test_/sk_test_ are the sandbox, pk_/sk_ live).
        |   TABBY_PUBLIC_KEY      creates the checkout session
        |   TABBY_SECRET_KEY      reads, captures, refunds and closes payments
        |   TABBY_MERCHANT_CODE   the merchant code of the country (e.g. SA, AE, KW)
        |   TABBY_WEBHOOK_SECRET  the value of the header Tabby sends with every webhook
        |                         (registered with `php artisan payments:tabby:register-webhook`)
        */
        'tabby' => [
            'enabled' => (bool) env('TABBY_ENABLED', false),
            'environment' => env('TABBY_ENV', 'sandbox'),   // sandbox | production (informs logs and webhook registration)
            'base_url' => env('TABBY_BASE_URL', 'https://api.tabby.ai'),
            'public_key' => env('TABBY_PUBLIC_KEY'),
            'secret_key' => env('TABBY_SECRET_KEY'),
            'merchant_code' => env('TABBY_MERCHANT_CODE'),
            'webhook_header' => env('TABBY_WEBHOOK_HEADER', 'X-Tabby-Webhook-Secret'),
            'webhook_secret' => env('TABBY_WEBHOOK_SECRET'),
            'timeout' => (int) env('TABBY_TIMEOUT', 15),
            'currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'QAR'],
        ],

        /*
        | Tamara (https://docs.tamara.co). Sandbox and production are different hosts.
        |   TAMARA_API_TOKEN           the merchant API token (a JWT from the Tamara portal)
        |   TAMARA_NOTIFICATION_TOKEN  signs the notifications Tamara sends us (verified on every one)
        */
        'tamara' => [
            'enabled' => (bool) env('TAMARA_ENABLED', false),
            'environment' => env('TAMARA_ENV', 'sandbox'),   // sandbox | production
            'base_url' => env('TAMARA_BASE_URL'),            // empty: https://api-sandbox.tamara.co or https://api.tamara.co by the environment
            'api_token' => env('TAMARA_API_TOKEN'),
            'notification_token' => env('TAMARA_NOTIFICATION_TOKEN'),
            'country_code' => env('TAMARA_COUNTRY_CODE', 'SA'),
            'timeout' => (int) env('TAMARA_TIMEOUT', 15),
            'currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'QAR', 'OMR'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the customer lands after paying (or not)
    |--------------------------------------------------------------------------
    |
    | The gateways send the customer back to OUR return endpoint, which asks the gateway what
    | really happened and then redirects here (an app deep link or a web page), adding
    | ?booking=<uuid>&status=<paid|pending|failed|cancelled|expired>. Unset: the endpoint answers JSON.
    |
    */
    'return_urls' => [
        'success' => env('PAYMENT_RETURN_SUCCESS_URL'),
        'cancel' => env('PAYMENT_RETURN_CANCEL_URL'),
        'failure' => env('PAYMENT_RETURN_FAILURE_URL'),
        // With no address above, the customer sees a PAGE with the status, the amount and the reference numbers; this is where its "Back to the app" button goes (empty: no button).
        'app' => env('PAYMENT_RETURN_APP_URL'),
    ],

    // A payment still waiting this many minutes after it was started is looked at by `payments:reconcile` (and settled if the webhook never came).
    'reconcile_after_minutes' => (int) env('PAYMENT_RECONCILE_AFTER_MINUTES', 2),

    // The log channel for payment events (null: the default channel). Keys, tokens and customer details are never logged.
    'log_channel' => env('PAYMENT_LOG_CHANNEL'),
];
