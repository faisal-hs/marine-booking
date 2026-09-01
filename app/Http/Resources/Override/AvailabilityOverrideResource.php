<?php

namespace App\Http\Resources\Override;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvailabilityOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'date_from' => $this->date_from->toDateString(),
            'date_to' => $this->date_to->toDateString(),
            'is_unavailable' => $this->is_unavailable,
            'price' => $this->price !== null ? (float) $this->price : null,
            'instructions' => $this->instructions,
            'status' => $this->status,
        ];
    }
}
