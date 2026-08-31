<?php

namespace App\Http\Resources\ActivityFeature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityFeatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->featureDefinition->key,
            'label' => $this->featureDefinition->label,
            'type' => $this->featureDefinition->type,
            'value' => $this->value,
        ];
    }
}
