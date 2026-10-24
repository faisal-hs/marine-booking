<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Verification documents
    |--------------------------------------------------------------------------
    |
    | What a provider uploads so an admin can verify them (business licence,
    | identity, diving or skipper certificates, insurance).
    |
    | 'disk' must stay a PRIVATE disk. 'local' is storage/app/private, which the
    | web server does not serve; the only way to read a file is the authenticated
    | admin download route. Never point this at the 'public' disk.
    |
    | Images are limited to raster formats and there is no SVG, for the same reason
    | activity photos exclude it: an SVG can carry a script.
    |
    */
    'documents' => [
        'disk' => 'local',
        'max_kb' => 5120,
        'max_per_provider' => 10,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'types' => ['business_license', 'identity_document', 'professional_certification', 'insurance', 'other'],
    ],
];
