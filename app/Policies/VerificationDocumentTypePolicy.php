<?php

namespace App\Policies;

use App\Models\User;

/** Who may configure which documents providers are asked for. */
class VerificationDocumentTypePolicy
{
    public function manage(User $user): bool
    {
        return $user->can('document_types.manage');
    }
}
