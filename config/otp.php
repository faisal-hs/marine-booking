<?php

return [
    'length' => (int) env('OTP_LENGTH', 4),
    'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 5),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Default OTP (a fixed code, for testing)
    |--------------------------------------------------------------------------
    |
    | When on, this fixed code is issued instead of a random one, to customers
    | and providers alike. The SMS is still "sent", but anyone who knows the
    | code can sign in without reading it.
    |
    | OTP_DEFAULT_CODE_ENABLED is the switch:
    |   true   on, in ANY environment (use it on a staging server that has no SMS
    |          provider yet).
    |   false  off everywhere, even locally (to test real codes).
    |   unset  on only in the environments listed below (local and testing),
    |          exactly as before.
    |
    | Turning it on in production is allowed because it is an explicit choice, but
    | it means ANYONE WHO KNOWS A PHONE NUMBER CAN SIGN IN AS THAT PERSON. It is
    | logged as a warning every time a code is issued. Switch it off before real
    | users sign in.
    |
    | OTP_DEFAULT_CODE must also be set; with the switch on and no code, random
    | codes are used and an error is logged.
    |
    */
    'default_code' => env('OTP_DEFAULT_CODE'),
    'default_code_enabled' => env('OTP_DEFAULT_CODE_ENABLED'),
    'default_code_environments' => ['local', 'testing'],
];
