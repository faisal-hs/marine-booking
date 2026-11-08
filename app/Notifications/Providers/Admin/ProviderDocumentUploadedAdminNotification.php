<?php

namespace App\Notifications\Providers\Admin;

use App\Models\Provider;
use App\Models\ProviderDocument;
use Illuminate\Notifications\Notification;

/** Tells the admins who review documents that a provider sent one in (a first upload or a new version). */
class ProviderDocumentUploadedAdminNotification extends Notification
{
    public function __construct(protected Provider $provider, protected ProviderDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin.provider_document.uploaded',
            'provider_uuid' => $this->provider->uuid,
            'business_name' => $this->provider->business_name,
            'document_type' => $this->document->type,
            'message' => "\"{$this->provider->business_name}\" uploaded a document ({$this->document->type}) for review.",
        ];
    }
}
