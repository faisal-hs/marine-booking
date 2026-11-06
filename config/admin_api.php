<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin API tokens
    |--------------------------------------------------------------------------
    |
    | Staff sign in to the JSON API (POST /api/v1/auth/admin/login) and receive a bearer token that
    | works on /api/v1/admin/*. Unlike customer and provider tokens, which stay valid until they sign
    | out, a staff token EXPIRES, because it can approve, refund and moderate. The default is a working day.
    |
    */
    'token_hours' => max(1, (int) env('ADMIN_API_TOKEN_HOURS', 8)),
];
