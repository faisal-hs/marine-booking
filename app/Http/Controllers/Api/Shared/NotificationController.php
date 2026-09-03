<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\Notification\NotificationResource;
use Illuminate\Http\Request;

/**
 * Shared across customer/provider/admin route groups — reading and
 * marking one's own notifications as read is identical regardless of
 * guard (auth:sanctum for customer/provider, auth:web for admin), it
 * only ever operates on $request->user()->notifications(), which
 * Laravel's Notifiable trait (already on User) scopes correctly per
 * user automatically via the notifiable_type/notifiable_id columns.
 */
class NotificationController extends BaseApiController
{
    public function index(PaginationRequest $request)
    {
        $notifications = $request->user()->notifications()
            ->paginate($request->perPage(), ['*'], 'current_page');

        return $this->success(NotificationResource::collection($notifications));
    }

    public function unreadCount(Request $request)
    {
        return $this->success(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markAsRead(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return $this->success(new NotificationResource($record->fresh()), 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->success(null, 'All notifications marked as read.');
    }
}
