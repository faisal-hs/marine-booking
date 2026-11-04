<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationLinkResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

/**
 * The signed-in admin's OWN notifications (new reservations, failed payments, provider
 * decisions ...). Every query goes through $request->user()->notifications(), so someone
 * else's entry cannot be reached by guessing its id: it is simply "not found".
 */
class NotificationController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, NotificationLinkResolver $links): View
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $status = $request->query('status') === 'unread' ? 'unread' : 'all';

        $query = $request->user()->notifications();
        if ($status === 'unread') {
            $query = $request->user()->unreadNotifications();
        }

        return view('admin.notifications.index', [
            'notifications' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'links' => $links,
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }

    public function markUnread(Request $request, string $notification): RedirectResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $request->user()->notifications()->findOrFail($notification)->markAsUnread();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', __('admin.notifications.all_marked_read'));
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $request->user()->notifications()->findOrFail($notification)->delete();

        return back()->with('status', __('admin.notifications.deleted'));
    }
}
