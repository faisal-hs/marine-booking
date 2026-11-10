<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer and a provider talking about an activity. */
class Conversation extends Model
{
    protected $fillable = ['customer_id', 'provider_id', 'activity_id', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
