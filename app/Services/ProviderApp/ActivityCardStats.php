<?php

namespace App\Services\ProviderApp;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures on a provider's activity card, each with one definition. They are worked out in the same query as the list (no query for each activity).
 *
 *   price / price_unit   the lowest active price of the activity's DEFAULT booking option (the one marked default, else the first active one), and how it
 *                        is charged: that option's booking model (per_person, per_group, per_trip, per_unit, per_hour, per_day, fixed or package). For a
 *                        per-person option it is the ADULT price. null when the activity has no priced active option yet.
 *   bookings_count       confirmed and completed bookings, for the activity's whole life
 *   views_count          page views, for the activity's whole life (one visitor on one day is one view)
 *   revenue              the wallet ledger's GROSS for the activity, for its whole life: the price of the trips that took place, after the provider's own
 *                        coupon and never the platform's service fee or VAT (the same gross the dashboard shows)
 *   rating, reviews_count   the average and the number of the visible reviews
 */
class ActivityCardStats
{
    private const DEFAULT_OPTION = 'SELECT o2.id FROM activity_booking_options o2 WHERE o2.activity_id = activities.id AND o2.is_active = 1 AND o2.deleted_at IS NULL ORDER BY o2.is_default DESC, o2.sort_order ASC, o2.id ASC LIMIT 1';

    public static function select(Builder $query): Builder
    {
        $price = "(SELECT MIN(pr.price) FROM activity_pricing_rules pr JOIN activity_booking_options o ON o.id = pr.activity_booking_option_id WHERE pr.is_active = 1 AND (o.booking_model <> 'per_person' OR pr.participant_type = 'adult') AND o.id = (".self::DEFAULT_OPTION.'))';
        $unit = '(SELECT o3.booking_model FROM activity_booking_options o3 WHERE o3.id = ('.self::DEFAULT_OPTION.'))';
        $bookings = "(SELECT COUNT(*) FROM reservations rv WHERE rv.activity_id = activities.id AND rv.status IN ('confirmed', 'completed') AND rv.deleted_at IS NULL)";
        $views = '(SELECT COUNT(*) FROM activity_views av WHERE av.activity_id = activities.id)';
        $revenue = "(SELECT COALESCE(SUM(w.amount), 0) FROM wallet_entries w JOIN reservations rr ON rr.id = w.reservation_id WHERE rr.activity_id = activities.id AND w.type = 'booking_income')";

        return $query->addSelect('activities.*')->selectRaw("{$price} AS card_price, {$unit} AS card_price_unit, {$bookings} AS card_bookings, {$views} AS card_views, {$revenue} AS card_revenue");
    }

    /** @return array<string, mixed> the card figures of an activity, from the list query when it was selected there, else worked out now */
    public static function of(Activity $activity): array
    {
        $row = array_key_exists('card_price', $activity->getAttributes()) ? $activity : self::select(Activity::query()->withoutGlobalScopes())->whereKey($activity->id)->first();

        return [
            'price' => $row->card_price !== null ? (float) $row->card_price : null,
            'price_unit' => $row->card_price_unit,
            'bookings_count' => (int) $row->card_bookings,
            'views_count' => (int) $row->card_views,
            'revenue' => round((float) $row->card_revenue, 2),
            'rating' => (float) $row->average_rating,
            'reviews_count' => (int) $row->reviews_count,
        ];
    }
}
