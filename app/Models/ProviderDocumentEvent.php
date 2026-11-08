<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a provider document's review history. Never edited. */
class ProviderDocumentEvent extends Model
{
    public const UPDATED_AT = null;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const UPDATE_REQUESTED = 'update_requested';

    protected $fillable = ['provider_id', 'document_type', 'provider_document_id', 'action', 'note', 'actor_user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function document()
    {
        return $this->belongsTo(ProviderDocument::class, 'provider_document_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
