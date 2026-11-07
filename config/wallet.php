<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider wallet
    |--------------------------------------------------------------------------
    |
    | Earnings are recorded in a ledger when a booking is COMPLETED. They become available to withdraw only after a waiting period,
    | counted from the end of the trip day. Everything here is configuration, not code.
    |
    */

    // Days between the end of the trip day and the moment the earnings of that booking can be withdrawn. 0 = at once.
    // The final value has not been decided; 0 means "as soon as the booking is completed".
    'settlement_delay_days' => (int) env('WALLET_SETTLEMENT_DELAY_DAYS', 0),

    // The smallest withdrawal a provider may ask for (in the platform currency). 0 = any positive amount.
    'min_withdrawal' => (float) env('WALLET_MIN_WITHDRAWAL', 0),

    // A fee kept from each withdrawal, as a percentage of the amount (the provider is paid amount - fee). 0 = no fee.
    // Whether such a fee applies, and who bears it, has not been decided; the spec's `fee` and `net_amount` fields exist and are 0 until it is.
    'withdrawal_fee_percent' => (float) env('WALLET_WITHDRAWAL_FEE_PERCENT', 0),

    // How long a statement download link works, in minutes.
    'statement_link_minutes' => (int) env('WALLET_STATEMENT_LINK_MINUTES', 15),
];
