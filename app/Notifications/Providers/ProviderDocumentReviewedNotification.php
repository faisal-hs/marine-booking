<?php

namespace App\Notifications\Providers;

use App\Models\Provider;
use App\Models\ProviderDocumentEvent;
use App\Notifications\Concerns\QueuedDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a provider what an admin decided about a document: approved, rejected (with the reason) or an update is needed (with the reason). */
class ProviderDocumentReviewedNotification extends Notification implements ShouldQueue
{
    use QueuedDelivery;

    public function __construct(protected Provider $provider, protected string $outcome, protected string $documentTitle, protected ?string $note = null) {}

    public function via(object $notifiable): array
    {
        return ['database', 'fcm', 'mail'];
    }

    private function sentence(): string
    {
        $reason = $this->note ? " Reason: {$this->note}" : '';

        return match ($this->outcome) {
            ProviderDocumentEvent::APPROVED => "Your {$this->documentTitle} was approved.",
            ProviderDocumentEvent::REJECTED => "Your {$this->documentTitle} was rejected.{$reason} Please upload a new one.",
            default => "An admin asked you to update your {$this->documentTitle}.{$reason}",
        };
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => "provider.document.{$this->outcome}", 'document' => $this->documentTitle, 'note' => $this->note, 'message' => $this->sentence()];
    }

    public function toFcm(object $notifiable): array
    {
        return ['title' => 'Verification document', 'body' => $this->sentence(), 'data' => ['type' => "provider.document.{$this->outcome}"]];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your verification document')->greeting("Hi {$notifiable->name},")->line($this->sentence());
    }
}
