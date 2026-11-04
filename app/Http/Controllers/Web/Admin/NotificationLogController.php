<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Every in-app notification the system has sent to anyone (customers, providers and staff),
 * for support and for checking that what should have been sent was.
 *
 * It shows the in-app entries only. Whether the push, SMS or e-mail for one of them was
 * delivered is not recorded here; a delivery that kept failing ends up in `failed_jobs`
 * (see the README: php artisan queue:failed).
 */
class NotificationLogController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $filters = $request->only(['recipient', 'type', 'status', 'from', 'to', 'search']);

        $query = DatabaseNotification::query()->latest();

        if (in_array($filters['recipient'] ?? null, ['customer', 'provider', 'admin'], true)) {
            $query->whereHasMorph('notifiable', [User::class], fn ($q) => $q->withTrashed()->where('user_type', $filters['recipient']));
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (($filters['status'] ?? null) === 'read') {
            $query->whereNotNull('read_at');
        } elseif (($filters['status'] ?? null) === 'unread') {
            $query->whereNull('read_at');
        }

        if ($from = $this->date($filters['from'] ?? null)) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($to = $this->date($filters['to'] ?? null)) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('data', 'like', $term)
                ->orWhereHasMorph('notifiable', [User::class], fn ($u) => $u->withTrashed()
                    ->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term))));
        }

        $notifications = $query->paginate(20)->withQueryString();
        $this->attachRecipients($notifications->getCollection());

        return view('admin.notifications.log', [
            'notifications' => $notifications,
            'filters' => $filters,
            'types' => DatabaseNotification::query()->select('type')->distinct()->orderBy('type')->limit(100)->pluck('type'),
        ]);
    }

    public function show(string $notification): View
    {
        $this->authorize('viewAny', DatabaseNotification::class);

        $entry = DatabaseNotification::findOrFail($notification);
        $this->attachRecipients(collect([$entry]));

        return view('admin.notifications.log-show', ['notification' => $entry]);
    }

    public function destroy(string $notification): RedirectResponse
    {
        $entry = DatabaseNotification::findOrFail($notification);

        $this->authorize('delete', $entry);

        $entry->delete();

        return redirect()->route('admin.notifications.log')->with('status', __('admin.notifications.deleted'));
    }

    /**
     * Sets each entry's recipient: one query for the users on the page, deleted ones included
     * so a deleted account's history still shows its name.
     *
     * Not Eloquent's morph eager-loading, on purpose: it instantiates whatever class the row
     * names, so a single old row pointing at a model that no longer exists would take the whole
     * page down with "class not found". A diagnostic page has to survive exactly such a row,
     * which here just shows no recipient.
     */
    private function attachRecipients(Collection $entries): void
    {
        $users = User::withTrashed()
            ->whereIn('id', $entries->where('notifiable_type', User::class)->pluck('notifiable_id')->unique())
            ->get()->keyBy('id');

        foreach ($entries as $entry) {
            $entry->setRelation('notifiable', $entry->notifiable_type === User::class ? $users->get($entry->notifiable_id) : null);
        }
    }

    /** An unparseable date in the URL is ignored rather than a server error. */
    private function date(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
