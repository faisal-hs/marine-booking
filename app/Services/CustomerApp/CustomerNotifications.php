<?php

namespace App\Services\CustomerApp;

use App\Models\User;
use App\Services\AccountService;
use App\Services\NotificationFeed;
use Illuminate\Notifications\DatabaseNotification;

/** A customer's notifications: the ones of the customer account only (a person who is also a provider keeps the two feeds apart). */
class CustomerNotifications
{
    public function __construct(private readonly NotificationFeed $feed) {}

    public function query(User $user)
    {
        return $this->feed->scope($user->notifications(), AccountService::CUSTOMER);
    }

    public function unreadCount(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->count();
    }

    /** The title of a kind of notification, in the language of the request; a kind that has none gets a plain "Notification". (The kinds contain dots, so they are looked up as they are, not as nested keys.) */
    private function title(string $type): string
    {
        $titles = (array) __('customer_app.notification_titles');

        return $titles[$type] ?? $titles['default'];
    }

    /** @return array<string, mixed> */
    public function present(DatabaseNotification $n): array
    {
        $type = (string) ($n->data['type'] ?? '');
        $group = match (true) {
            str_starts_with($type, 'reservation.') => 'booking',
            str_starts_with($type, 'payment.') => 'payment',
            default => 'general',
        };

        return [
            // A notification's id is a UUID, not a counter.
            'id' => $n->id,
            'type' => $group,
            'title' => $this->title($type),
            'body' => $n->data['message'] ?? '',
            'is_read' => $n->read_at !== null,
            'read_at' => $n->read_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $n->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'booking_id' => $n->data['reservation_id'] ?? null,
        ];
    }
}
