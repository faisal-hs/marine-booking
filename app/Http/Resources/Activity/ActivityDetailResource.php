<?php

namespace App\Http\Resources\Activity;

use App\Services\PriceOptions;
use App\Support\ReservationType;
use Illuminate\Http\Request;

/**
 * The activity as the provider sees it on its own page: everything of ActivityResource, and its PRICE OPTIONS and RESERVATION TYPE in the same words the customer app uses on the
 * customer's page. (The list keeps ActivityResource: a price options block for every activity of a page would be a lot of data nobody reads there.)
 *
 *   reservation_type   what a booking of the activity's default option is made of: persons, units, time or fixed (see App\Support\ReservationType)
 *   price_options      every booking option (the inactive ones too, marked `is_active`) with its reservation type, its limits and its prices (see App\Services\PriceOptions)
 */
class ActivityDetailResource extends ActivityResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $options = app(PriceOptions::class)->forActivity($this->resource, activeOnly: false);
        // The default option is the one the card's price is of: the active one marked default, else the first active one (ActivityCardStats).
        $default = collect($options)->first(fn (array $o) => $o['is_active'] && $o['is_default']) ?? collect($options)->first(fn (array $o) => $o['is_active']);

        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $value;

            if ($key === 'price_unit') {
                $result['reservation_type'] = $default['reservation_type'] ?? ReservationType::PERSONS;
            }
        }

        return $result + ['price_options' => $options];
    }
}
