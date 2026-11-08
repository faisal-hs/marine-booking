<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic publishing
    |--------------------------------------------------------------------------
    |
    | Nobody approves an activity. The admin approves the PROVIDER's account; an approved provider creates, edits and publishes their own activities.
    | This setting decides what the provider's PUBLISH REQUEST does:
    |
    |   true   the activity goes live at once (the default);
    |   false  the activity becomes "ready" and stays hidden until the provider activates it
    |          (PATCH /api/v1/provider/activities/{id}/status with {"status": "active"}), so the provider chooses the launch moment.
    |
    | Activating an activity yourself always makes it live. It applies to publish requests made after it is changed; activities already live stay live.
    |
    | An admin can stop any activity at any time (a suspension, with a reason). That is kept apart from the provider's own pausing, and a provider can
    | never switch a suspended activity back on: only an admin lifting the suspension can.
    |
    */
    'auto_publish' => filter_var(env('ACTIVITY_AUTO_PUBLISH', true), FILTER_VALIDATE_BOOLEAN),
];
