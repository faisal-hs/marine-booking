<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'min_participants' => 1,
    ];

    protected $fillable = [
        'provider_id',
        'category_id',
        'title',
        'slug',
        'description',
        'location',
        'meeting_point',
        'lat',
        'lng',
        'duration_minutes',
        'min_participants',
        'max_participants',
        'min_age',
        'status',
        'rejection_reason',
        'published_at',
        'average_rating',
        'reviews_count',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'published_at' => 'datetime',
        'average_rating' => 'decimal:2',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function visibleReviews()
    {
        return $this->reviews()->where('is_hidden', false);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function images()
    {
        return $this->hasMany(ActivityImage::class)->orderBy('sort_order');
    }

    public function coverImage()
    {
        return $this->hasOne(ActivityImage::class)->where('is_cover', true);
    }

    public function featureValues()
    {
        return $this->hasMany(ActivityFeatureValue::class);
    }

    public function options()
    {
        return $this->hasMany(ActivityOption::class)->orderBy('sort_order');
    }

    public function requirements()
    {
        return $this->hasMany(ActivityRequirement::class)->orderBy('sort_order');
    }

    public function inclusions()
    {
        return $this->hasMany(ActivityInclusion::class)->orderBy('sort_order');
    }

    public function schedulePeriods()
    {
        return $this->hasMany(ActivitySchedulePeriod::class)->orderBy('day_of_week')->orderBy('sort_order');
    }

    public function availabilityOverrides()
    {
        return $this->hasMany(ActivityAvailabilityOverride::class);
    }

    public function bookingOptions()
    {
        return $this->hasMany(ActivityBookingOption::class)->orderBy('sort_order');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * The lowest active price across this activity's active booking
     * options — used for the "starting from" price shown on public
     * activity cards. Deliberately a live per-call query rather than a
     * denormalized column (unlike average_rating/reviews_count): prices
     * change far more often via admin/provider edits than reviews
     * accumulate, and the homepage only ever renders a small, bounded
     * number of cards (8 featured + 8 top-rated), so the extra query per
     * card is an acceptable N+1 for this scale rather than a genuine
     * performance concern worth denormalizing for.
     */
    public function getStartingPriceAttribute(): ?float
    {
        $price = $this->bookingOptions()
            ->where('activity_booking_options.is_active', true)
            ->join('activity_pricing_rules', 'activity_pricing_rules.activity_booking_option_id', '=', 'activity_booking_options.id')
            ->where('activity_pricing_rules.is_active', true)
            ->min('activity_pricing_rules.price');

        return $price !== null ? (float) $price : null;
    }
}
