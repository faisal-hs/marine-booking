<?php

namespace App\Services;

use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Models\User;
use App\Notifications\Broadcasts\BroadcastNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Compose, send and delete announcements.
 *
 * SENDING is two steps so a click can never send twice and a large audience never ties up
 * a web request: send() atomically moves the broadcast from draft to "sending" (an UPDATE that
 * only matches a draft, so two clicks cannot both win) and queues a job; the job then hands
 * one notification per recipient to NotificationDispatchService. That service is idempotent
 * per (recipient, event key), so a retried or restarted job cannot message anyone twice, and
 * one broken recipient cannot stop the others.
 *
 * WHO RECEIVES IT: active, non-deleted customers or providers (or the one named account).
 * Staff, suspended and deleted accounts never receive an announcement.
 */
class BroadcastService
{
    public function __construct(protected NotificationDispatchService $dispatcher) {}

    public function create(array $data, User $author): Broadcast
    {
        return Broadcast::create($this->fields($data) + ['status' => 'draft', 'created_by' => $author->id]);
    }

    public function update(Broadcast $broadcast, array $data): Broadcast
    {
        $this->assertDraft($broadcast, 'Only a draft can be edited. A broadcast that has been sent cannot be changed.');

        $broadcast->update($this->fields($data));

        return $broadcast->fresh();
    }

    public function send(Broadcast $broadcast): Broadcast
    {
        $this->assertDraft($broadcast, 'This broadcast has already been sent.');

        $count = $this->recipients($broadcast)->count();

        if ($count === 0) {
            throw ValidationException::withMessages(['send' => [__('admin.notifications.no_recipients')]]);
        }

        // Only a draft matches, so two clicks (or two admins) cannot both start the send.
        $claimed = Broadcast::whereKey($broadcast->id)->where('status', 'draft')
            ->update(['status' => 'sending', 'recipients_count' => $count, 'delivered_count' => 0, 'failed_reason' => null]);

        if ($claimed === 0) {
            throw ValidationException::withMessages(['send' => ['This broadcast has already been sent.']]);
        }

        SendBroadcast::dispatch($broadcast->id);

        return $broadcast->fresh();
    }

    /** Runs in the queue. Safe to run twice: nobody is notified twice. */
    public function deliver(int $broadcastId): void
    {
        $broadcast = Broadcast::find($broadcastId);

        if ($broadcast === null || $broadcast->status !== 'sending') {
            return;
        }

        $delivered = 0;

        $this->recipients($broadcast)->chunkById(200, function ($users) use ($broadcast, &$delivered) {
            foreach ($users as $user) {
                $handedOver = $this->dispatcher->dispatchOnce(
                    "broadcast:{$broadcast->id}",
                    $user,
                    new BroadcastNotification($broadcast->title, $broadcast->body, $broadcast->uuid)
                );

                $delivered += $handedOver ? 1 : 0;
            }
        });

        $broadcast->update(['status' => 'sent', 'delivered_count' => $delivered, 'sent_at' => now()]);
    }

    public function markFailed(int $broadcastId, string $reason): void
    {
        Broadcast::whereKey($broadcastId)->where('status', 'sending')
            ->update(['status' => 'failed', 'failed_reason' => mb_substr($reason, 0, 500)]);
    }

    public function delete(Broadcast $broadcast): void
    {
        if ($broadcast->status === 'sending') {
            throw ValidationException::withMessages(['delete' => [__('admin.notifications.cannot_delete_sending')]]);
        }

        // Only the announcement record goes. Notifications already delivered stay in the
        // recipients' feeds; removing those is a separate act (the all-notifications log).
        $broadcast->delete();
    }

    /** @return Builder<User> */
    public function recipients(Broadcast $broadcast): Builder
    {
        $query = User::query()->where('status', 'active'); // soft-deleted users are excluded by default

        return match ($broadcast->audience) {
            // By ACCOUNT, not by the type someone registered as: a person with both gets both kinds.
            // "Providers" means approved ones: an unapproved account has nothing to announce to yet.
            'customers' => $query->whereHas('customer'),
            'providers' => $query->whereHas('provider', fn($p) => $p->where('verification_status', 'verified')),
            'user' => $query->whereKey($broadcast->recipient_user_id)->where(fn($q) => $q->whereHas('customer')->orWhereHas('provider')),
        };
    }

    /** Finds the person an admin typed in (an e-mail address or a phone number). */
    public function findRecipient(string $emailOrPhone): ?User
    {
        return User::query()
            ->where(fn($q) => $q->where('email', $emailOrPhone)->orWhere('phone', $emailOrPhone))
            ->first();
    }

    private function assertDraft(Broadcast $broadcast, string $message): void
    {
        if (! $broadcast->isDraft()) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function fields(array $data): array
    {
        return [
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'recipient_user_id' => $data['audience'] === 'user' ? $data['recipient_user_id'] : null,
        ];
    }
}
