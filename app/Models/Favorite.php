<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    protected $fillable = [
        'customer_id',
        'activity_id',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
