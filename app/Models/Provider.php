<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use HasUuid, SoftDeletes;

    /**
     * PHP-side defaults mirroring the migration's DB-level defaults,
     * so a freshly-created (not refreshed) model reflects correct values
     * immediately — Eloquent does not re-fetch DB column defaults automatically.
     */
    protected $attributes = [
        'verification_status' => 'pending',
        'commission_rate' => 0,
    ];

    protected $fillable = [
        'user_id',
        'business_name',
        'slug',
        'business_license',
        'bio',
        'logo',
        'address',
        'lat',
        'lng',
        'verification_status',
        'rejection_reason',
        'commission_rate',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'commission_rate' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }


    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function documents()
    {
        return $this->hasMany(ProviderDocument::class)->latest()->latest('id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
