<?php

namespace App\Http\Resources\ActivityOption;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'extra_price' => (float) $this->extra_price,
            'is_required' => $this->is_required,
            'max_quantity' => $this->max_quantity,
        ];
    }
}
