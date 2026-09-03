<?php

namespace App\Http\Resources\Activity;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => $this->category->name,
            'provider' => $this->provider->business_name,
            'location' => $this->location,
            'duration_minutes' => $this->duration_minutes,
            'average_rating' => (float) $this->average_rating,
            'reviews_count' => $this->reviews_count,
        ];
    }
}
