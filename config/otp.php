<?php

return [
    'length' => (int) env('OTP_LENGTH', 4),
    'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 5),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Default OTP (dev/testing only)
    |--------------------------------------------------------------------------
    |
    | When set, this fixed code is issued instead of a random one, but ONLY
    | in the environments listed below. The SMS is still "sent" (so you can
    | confirm gateway wiring works), but you can log in without reading it.
    | This must never be enabled in production — enforced below.
    |
    */
    'default_code' => env('OTP_DEFAULT_CODE'),
    'default_code_environments' => ['local', 'testing'],
];
