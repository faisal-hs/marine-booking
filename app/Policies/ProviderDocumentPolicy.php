<?php

namespace App\Policies;

use App\Models\ProviderDocument;
use App\Models\User;

class ProviderDocumentPolicy
{
    /**
     * Reading a provider's verification documents is for admins who may view
     * providers. Providers manage their own through the provider API, which checks
     * ownership itself (and answers 404 for someone else's document).
     */
    public function download(User $user, ProviderDocument $document): bool
    {
        return $user->can('providers.view');
    }
}
