<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasUuid;

    protected $attributes = [
        'is_hidden' => false,
    ];

    protected $fillable = [
        'customer_id',
        'activity_id',
        'reservation_id',
        'rating',
        'comment',
        'is_hidden',
        'hidden_reason',
        'hidden_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_hidden' => 'boolean',
        'hidden_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
