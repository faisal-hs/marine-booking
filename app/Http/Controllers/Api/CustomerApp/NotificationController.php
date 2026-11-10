<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\CustomerApp\PageRequest;
use App\Services\CustomerApp\CustomerNotifications;
use Illuminate\Http\Request;

/** The customer's notifications, private to them. */
class NotificationController extends CustomerAppController
{
    public function __construct(private readonly CustomerNotifications $notifications) {}

    public function index(PageRequest $request)
    {
        $page = $this->notifications->query($request->user())->latest()->latest('id')->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));

        return $this->page($page, collect($page->items())->map(fn ($n) => $this->notifications->present($n))->all());
    }

    /** Safe to repeat. Another customer's notification, and one that does not exist, are both "not found". */
    public function read(Request $request, string $id)
    {
        $notification = $this->notifications->query($request->user())->where('id', $id)->first() ?? throw ProviderApiException::notFound('Notification not found.');

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $this->ok(['id' => $notification->id, 'is_read' => true], 'Marked as read');
    }

    public function readAll(Request $request)
    {
        $marked = $this->notifications->query($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return $this->ok(['marked' => $marked], 'All marked as read');
    }
}
