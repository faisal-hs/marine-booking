<?php

namespace App\Services\CustomerApp;

use App\Exceptions\ProviderApiException;
use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;

/**
 * A customer's conversations with providers. One conversation per customer, provider and activity; the provider must be one that can be talked to
 * (verified) and the activity must be theirs and one the customer can see. A conversation is only ever reached by the customer in it.
 */
class Messaging
{
    public function __construct(private readonly ActivityCatalog $catalog) {}

    /** @return array{0: Conversation, 1: bool} the conversation and whether it was just made */
    public function start(Customer $customer, int $providerId, int $activityId): array
    {
        $activity = $this->catalog->find($activityId);
        $provider = Provider::where('verification_status', 'verified')->find($providerId);

        // A made-up provider, an unverified one, and an activity that is not theirs (or not visible) are all the same "not found".
        if (! $activity || ! $provider || $activity->provider_id !== $provider->id) {
            throw ProviderApiException::notFound('Provider or activity not found.');
        }

        $conversation = Conversation::firstOrCreate(['customer_id' => $customer->id, 'provider_id' => $provider->id, 'activity_id' => $activity->id]);

        return [$conversation, $conversation->wasRecentlyCreated];
    }

    public function own(Customer $customer, string $id): Conversation
    {
        return (ctype_digit($id) ? Conversation::where('customer_id', $customer->id)->find((int) $id) : null) ?? throw ProviderApiException::notFound('Conversation not found.');
    }

    public function send(Conversation $conversation, string $text): Message
    {
        return DB::transaction(function () use ($conversation, $text) {
            $message = Message::create(['conversation_id' => $conversation->id, 'sender_type' => Message::CUSTOMER, 'body' => $text, 'created_at' => now()]);
            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        });
    }

    /** What the customer has not read yet: the provider's messages nobody opened. */
    public function unreadCount(Conversation $c): int
    {
        return $c->messages()->where('sender_type', Message::PROVIDER)->whereNull('read_at')->count();
    }

    /** @return array<string, mixed> */
    public function present(Conversation $c): array
    {
        $c->loadMissing(['provider', 'lastMessage']);
        $last = $c->lastMessage;

        return [
            'id' => $c->id,
            'user' => [
                'id' => $c->provider->id,
                'name' => $c->provider->business_name,
                'avatar' => $this->catalog->url($c->provider->logo),
                // There is no presence service behind the platform, so whether a provider is online is not known.
                'is_online' => null,
            ],
            'last_message' => $last ? ['text' => $last->body, 'sent_at' => $last->created_at->utc()->format('Y-m-d\TH:i:s\Z')] : null,
            'unread_count' => $this->unreadCount($c),
        ];
    }

    /** @return array<string, mixed> */
    public function presentMessage(Message $m): array
    {
        return ['id' => $m->id, 'text' => $m->body, 'is_mine' => $m->sender_type === Message::CUSTOMER, 'is_read' => $m->read_at !== null, 'sent_at' => $m->created_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }
}
