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

    /**
     * Includes a soft-deleted account on purpose, so reservations, payments and
     * reviews of a deleted customer still show who made them. Anything that SENDS
     * something to the user must check trashed(); NotificationDispatchService does.
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
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
