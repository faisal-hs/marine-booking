<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A notification from a payment gateway, kept once (see the migration). */
class PaymentEvent extends Model
{
    protected $fillable = ['gateway', 'event_key', 'payment_id', 'type', 'payload', 'result'];

    protected $casts = ['payload' => 'array'];
}
