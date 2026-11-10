<?php

namespace App\Http\Controllers\Api\CustomerApp;

use App\Http\Requests\CustomerApp\PageRequest;
use App\Http\Requests\CustomerApp\SendMessageRequest;
use App\Http\Requests\CustomerApp\StartConversationRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\CustomerApp\Messaging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The customer's conversations with providers, and the messages in them. */
class ConversationController extends CustomerAppController
{
    public function __construct(private readonly Messaging $messaging) {}

    public function store(StartConversationRequest $request)
    {
        [$conversation, $made] = $this->messaging->start($this->customer($request), $request->integer('provider_id'), $request->integer('activity_id'));

        $data = $this->messaging->present($conversation);

        return $made ? $this->created($data, 'Conversation started') : $this->ok($data);
    }

    /** The latest activity first; one nobody has written in yet comes last. */
    public function index(PageRequest $request)
    {
        $page = Conversation::where('customer_id', $this->customer($request)->id)->with(['provider', 'lastMessage'])
            ->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->integer('page', 1));

        return $this->page($page, collect($page->items())->map(fn ($c) => $this->messaging->present($c))->all());
    }

    /**
     * The newest messages first (page 1 is the latest), 30 a page. Opening a page marks the PROVIDER's messages on it as read, which is how the unread
     * count goes down; there is no separate read receipt.
     */
    public function messages(PageRequest $request, string $id)
    {
        $conversation = $this->messaging->own($this->customer($request), $id);

        $page = $conversation->messages()->orderByDesc('id')->paginate($request->perPage((int) config('customer_app.messages_per_page')), ['*'], 'page', $request->integer('page', 1));
        $data = collect($page->items())->map(fn (Message $m) => $this->messaging->presentMessage($m))->all();

        $unread = collect($page->items())->filter(fn (Message $m) => $m->sender_type === Message::PROVIDER && $m->read_at === null)->pluck('id');
        if ($unread->isNotEmpty()) {
            Message::whereIn('id', $unread)->update(['read_at' => now()]);
        }

        return $this->page($page, $data);
    }

    public function send(SendMessageRequest $request, string $id)
    {
        $conversation = $this->messaging->own($this->customer($request), $id);

        $message = $this->messaging->send($conversation, $request->validated('text'));

        return $this->created($this->messaging->presentMessage($message), 'Message sent');
    }
}
