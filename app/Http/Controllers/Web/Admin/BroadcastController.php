<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Notification\StoreBroadcastRequest;
use App\Http\Requests\Web\Admin\Notification\UpdateBroadcastRequest;
use App\Models\Broadcast;
use App\Services\BroadcastService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BroadcastService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Broadcast::class);

        $status = in_array($request->query('status'), ['draft', 'sending', 'sent', 'failed'], true) ? $request->query('status') : null;

        $broadcasts = Broadcast::query()->with(['creator', 'recipient'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest()->paginate(15)->withQueryString();

        return view('admin.notifications.broadcasts.index', ['broadcasts' => $broadcasts, 'status' => $status]);
    }

    public function create(): View
    {
        $this->authorize('create', Broadcast::class);

        return view('admin.notifications.broadcasts.create');
    }

    public function store(StoreBroadcastRequest $request): RedirectResponse
    {
        $broadcast = $this->service->create($request->payload(), $request->user());

        return redirect()->route('admin.notifications.broadcasts.show', $broadcast)->with('status', __('admin.notifications.draft_saved'));
    }

    public function show(Broadcast $broadcast): View
    {
        $this->authorize('view', $broadcast);

        $broadcast->load(['creator', 'recipient']);

        return view('admin.notifications.broadcasts.show', [
            'broadcast' => $broadcast,
            // What a send would reach right now (a sent broadcast shows the number it reached).
            'audienceSize' => $broadcast->isDraft() ? $this->service->recipients($broadcast)->count() : $broadcast->recipients_count,
        ]);
    }

    public function edit(Broadcast $broadcast): View|RedirectResponse
    {
        $this->authorize('update', $broadcast);

        if (! $broadcast->isDraft()) {
            return redirect()->route('admin.notifications.broadcasts.show', $broadcast)->with('error', __('admin.notifications.only_drafts_editable'));
        }

        $broadcast->load('recipient');

        return view('admin.notifications.broadcasts.edit', ['broadcast' => $broadcast]);
    }

    public function update(UpdateBroadcastRequest $request, Broadcast $broadcast): RedirectResponse
    {
        $this->authorize('update', $broadcast);

        try {
            $this->service->update($broadcast, $request->payload());
        } catch (ValidationException $e) {
            return redirect()->route('admin.notifications.broadcasts.show', $broadcast)->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.notifications.broadcasts.show', $broadcast)->with('status', __('admin.notifications.draft_saved'));
    }

    public function send(Broadcast $broadcast): RedirectResponse
    {
        $this->authorize('send', $broadcast);

        try {
            $this->service->send($broadcast);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.notifications.broadcasts.show', $broadcast)->with('status', __('admin.notifications.sending_started'));
    }

    public function destroy(Broadcast $broadcast): RedirectResponse
    {
        $this->authorize('delete', $broadcast);

        try {
            $this->service->delete($broadcast);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.notifications.broadcasts.index')->with('status', __('admin.notifications.broadcast_deleted'));
    }
}
