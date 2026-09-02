<?php

namespace App\Http\Resources\PricingRule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityPricingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'participant_type' => $this->participant_type?->value,
            'price' => (float) $this->price,
            'min_quantity' => $this->min_quantity,
            'max_quantity' => $this->max_quantity,
            'schedule_period_id' => $this->schedule_period_id,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
        ];
    }
}
