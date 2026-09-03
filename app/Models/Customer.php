<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'date_of_birth',
        'preferred_language',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoriteActivities()
    {
        return $this->belongsToMany(Activity::class, 'favorites');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
