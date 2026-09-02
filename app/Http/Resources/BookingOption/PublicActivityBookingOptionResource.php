<?php

namespace App\Http\Resources\BookingOption;

use App\Http\Resources\PricingRule\ActivityPricingRuleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing variant of ActivityBookingOptionResource. Only ever
 * instantiated with an already-active-filtered booking option (see
 * PublicActivityResource), and additionally filters its OWN nested
 * pricing rules to active-only — spec §35: "Public activity responses
 * should expose active booking options only. Do NOT expose inactive/
 * private configuration."
 */
class PublicActivityBookingOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'booking_model' => $this->booking_model->value,
            'min_participants' => $this->min_participants,
            'max_participants' => $this->max_participants,
            'min_quantity' => $this->min_quantity,
            'max_quantity' => $this->max_quantity,
            'min_duration' => $this->min_duration,
            'max_duration' => $this->max_duration,
            'duration_unit' => $this->duration_unit,
            'is_default' => $this->is_default,
            'pricing_rules' => ActivityPricingRuleResource::collection(
                $this->pricingRules->where('is_active', true)->values()
            ),
        ];
    }
}
