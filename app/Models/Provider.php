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
        'city',
        'area',
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

    /**
     * Includes a soft-deleted account on purpose. Deleting an account must not make
     * the pages and API resources that still show "who" (reservations, payments,
     * reviews, the dashboard) fail with a null error. Anything that SENDS something
     * to the user must check trashed(); NotificationDispatchService does.
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }


    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function documents()
    {
        return $this->hasMany(ProviderDocument::class)->latest()->latest('id');
    }

    public function requests()
    {
        return $this->hasMany(ProviderRequest::class)->latest('submitted_at')->latest('id');
    }

    public function latestRequest()
    {
        return $this->hasOne(ProviderRequest::class)->latestOfMany('id');
    }

    /** The most recent document of a type (documents() is newest first), or null. */
    public function documentOfType(string $type): ?ProviderDocument
    {
        return $this->documents->firstWhere('type', $type);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }
}
