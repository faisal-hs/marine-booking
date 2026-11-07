<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform currency
    |--------------------------------------------------------------------------
    |
    | The currency of every price, booking, payment, commission and balance the platform creates: Saudi Riyal (SAR).
    |
    | It is written onto each record when the record is made (reservations, payments and pricing rules all have a currency
    | column), so a record always says what it was made in. Changing this later affects only NEW records: nothing already stored is
    | ever converted or relabelled, because converting a historical amount silently would falsify it.
    |
    */
    'currency' => strtoupper((string) env('PLATFORM_CURRENCY', 'SAR')),
];
