<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps Laravel's native DatabaseNotification model — the 'data' column
 * already contains whatever each Notification class's toArray() method
 * produced (type, message, and event-specific fields), so this Resource
 * stays a thin pass-through rather than re-deriving anything.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->data['type'] ?? class_basename($this->type),
            'data' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
