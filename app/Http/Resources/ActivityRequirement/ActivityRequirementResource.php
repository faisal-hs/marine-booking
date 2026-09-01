<?php

namespace App\Http\Resources\ActivityRequirement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'text' => $this->text,
            'is_mandatory' => $this->is_mandatory,
        ];
    }
}
