<?php

namespace App\Models;

use App\Support\Money\Commission;
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
        'commission_type' => 'percentage',
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
        'business_type',
        'marina_name',
        'contact_phone',
        'contact_email',
        'lat',
        'lng',
        'verification_status',
        'rejection_reason',
        'notify_email',
        'notify_sms',
        'notify_whatsapp',
        'notify_news_and_offers',
        'commission_rate',
        'commission_type',
        'bank_account',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'commission_rate' => 'decimal:2',
        'bank_account' => 'encrypted:array',
        'notify_email' => 'boolean',
        'notify_sms' => 'boolean',
        'notify_whatsapp' => 'boolean',
        'notify_news_and_offers' => 'boolean',
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

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    public function walletEntries()
    {
        return $this->hasMany(WalletEntry::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    /** The commission on a booking worth $base to this provider, under their own terms (a percentage or a fixed amount). */
    public function commissionOn(float $base): float
    {
        return Commission::calculate((string) $this->commission_type, (float) $this->commission_rate, $base);
    }

    public function profileChanges()
    {
        return $this->hasMany(ProviderProfileChange::class);
    }

    /** The change to critical profile information that is waiting for an admin, if any (there is at most one). */
    public function pendingProfileChange()
    {
        return $this->hasOne(ProviderProfileChange::class)->where('status', ProviderProfileChange::PENDING)->latest('id');
    }

    /** The categories the provider works in (many to many). */
    public function categories()
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    /** The versions in force: one per upload nobody has replaced. */
    public function documents()
    {
        return $this->hasMany(ProviderDocument::class)->whereNull('superseded_at')->latest()->latest('id');
    }

    /** Every version ever uploaded, replaced ones too: the record an approval was based on. */
    public function documentVersions()
    {
        return $this->hasMany(ProviderDocument::class)->latest()->latest('id');
    }

    /** The review history of this provider's documents, oldest first. */
    public function documentEvents()
    {
        return $this->hasMany(ProviderDocumentEvent::class)->oldest()->oldest('id');
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

    /**
     * How this provider wants to be told things, as the app shows it: by email, by SMS, by WhatsApp, and whether they want news and offers (each true or false).
     * It is the one definition: the profile carries it as `notification`, and GET/PUT /provider/notification-preferences answers with it.
     *
     * @return array{email: bool, sms: bool, whatsapp: bool, news_and_offers: bool}
     */
    public function notificationPreferences(): array
    {
        return [
            'email' => (bool) $this->notify_email,
            'sms' => (bool) $this->notify_sms,
            'whatsapp' => (bool) $this->notify_whatsapp,
            'news_and_offers' => (bool) $this->notify_news_and_offers,
        ];
    }
}
