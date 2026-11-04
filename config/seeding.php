<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bulk demo data
    |--------------------------------------------------------------------------
    |
    | The Bulk seeders (database/seeders/Bulk) fill every table with a large, internally
    | consistent data set for load testing and for building screens against realistic volumes.
    |
    | BULK_SEED_SCALE multiplies every volume: 1 is the full set (about 1.3 million rows, 370 MB in
    | SQLite, seeded in about 75 seconds), 0.1 a tenth of it (about 8 seconds), 0.01 a smoke-test set. Each seeder's own
    | "volume at scale 1" is in its class comment and in the README.
    |
    | They refuse to run in production: they create accounts and data that must never exist there.
    |
    */
    'bulk_scale' => (float) env('BULK_SEED_SCALE', 1),

    // Everything is derived from this, so the same scale always produces the same data.
    'seed' => (int) env('BULK_SEED_RANDOM', 20261103),
];
