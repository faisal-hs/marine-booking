<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One message in a conversation, from the customer or the provider. */
class Message extends Model
{
    public const UPDATED_AT = null;

    public const CUSTOMER = 'customer';

    public const PROVIDER = 'provider';

    protected $fillable = ['conversation_id', 'sender_type', 'body', 'read_at', 'created_at'];

    protected $casts = ['read_at' => 'datetime', 'created_at' => 'datetime'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
