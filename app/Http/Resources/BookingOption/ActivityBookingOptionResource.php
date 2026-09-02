<?php

namespace App\Http\Resources\BookingOption;

use App\Http\Resources\PricingRule\ActivityPricingRuleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityBookingOptionResource extends JsonResource
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
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,
            'pricing_rules' => ActivityPricingRuleResource::collection($this->whenLoaded('pricingRules')),
        ];
    }
}
