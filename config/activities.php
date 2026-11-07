<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic publishing
    |--------------------------------------------------------------------------
    |
    | An admin must approve every activity before customers can see it; that never changes. This setting decides what approval does:
    |
    |   true   the approved activity is published at once (the behaviour until now, and the default);
    |   false  the activity becomes "approved" and stays hidden until the provider publishes it
    |          (PATCH /api/v1/provider/activities/{id}/status with {"status": "active"}), so the provider chooses the launch moment.
    |
    | It applies to approvals made after it is changed; activities already published stay published.
    |
    */
    'auto_publish' => filter_var(env('ACTIVITY_AUTO_PUBLISH', true), FILTER_VALIDATE_BOOLEAN),
];
