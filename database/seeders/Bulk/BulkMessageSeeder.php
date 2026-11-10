<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The messages of the conversations: two to twelve each, the customer first and then turn and turn about, every one later than the one before and
 * none in the future. A message more than a day old has been read; the provider's recent ones are unread about half the time (that is the customer's
 * unread count). Each conversation's last-message time is set to its newest message. Conversations that already have messages are left alone.
 * Volume at scale 1: about sixty thousand messages.
 * Prerequisites: BulkConversationSeeder.
 */
class BulkMessageSeeder extends BulkSeeder
{
    private const CUSTOMER = ['Hi, is the trip still on?', 'Can I bring a child of 8?', 'What should I wear?', 'Is there parking at the marina?', 'Can we move to an earlier time?', 'Thank you!'];

    private const PROVIDER = ['Yes, see you there.', 'Of course, children are welcome.', 'Light clothes and a towel.', 'Yes, free parking at the gate.', 'Let me check and come back to you.', 'You are welcome.'];

    protected function seed(): void
    {
        $this->needs('conversations', 'BulkConversationSeeder');

        $busy = array_flip(DB::table('messages')->distinct()->pluck('conversation_id')->all());

        DB::table('conversations')->orderBy('id')->get(['id', 'created_at'])->chunk(2000)->each(function ($conversations) use ($busy) {
            $this->insert('messages', (function () use ($conversations, $busy) {
                foreach ($conversations as $c) {
                    if (isset($busy[$c->id])) {
                        continue;
                    }

                    $at = Carbon::parse($c->created_at);

                    foreach (range(1, $this->rand(2, 12)) as $n) {
                        $at = $at->copy()->addMinutes($this->rand(1, 600));
                        if ($at->greaterThan($this->now)) {
                            break;
                        }

                        $mine = $n % 2 === 1;
                        $read = $at->lessThan($this->now->copy()->subDay()) || ($mine ? $this->chance(80) : $this->chance(50));

                        yield ['conversation_id' => $c->id, 'sender_type' => $mine ? 'customer' : 'provider', 'body' => $this->pick($mine ? self::CUSTOMER : self::PROVIDER),
                            'read_at' => $read ? $this->ts($at->copy()->addMinutes($this->rand(1, 120))->min($this->now)) : null, 'created_at' => $this->ts($at)];
                    }
                }
            })());
        });

        DB::statement('UPDATE conversations SET last_message_at = (SELECT MAX(created_at) FROM messages WHERE messages.conversation_id = conversations.id) WHERE last_message_at IS NULL');
    }
}
