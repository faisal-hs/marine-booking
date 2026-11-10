<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A customer's message to support. */
class SupportTicket extends Model
{
    use HasUuid;

    protected $fillable = ['customer_id', 'reservation_id', 'topic', 'message', 'attachment_path', 'attachment_name', 'status'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
