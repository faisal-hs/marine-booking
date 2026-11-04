<?php

namespace App\Http\Requests\Web\Admin\Notification;

class UpdateBroadcastRequest extends StoreBroadcastRequest
{
    // Same fields and the same checks as when composing it. BroadcastService::update refuses
    // anything that is not a draft any more.
}
