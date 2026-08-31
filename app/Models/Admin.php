<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Admin extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id',
        'job_title',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
