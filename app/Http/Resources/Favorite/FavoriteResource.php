<?php

namespace App\Http\Resources\Favorite;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity' => [
                'id' => $this->activity->id,
                'uuid' => $this->activity->uuid,
                'title' => $this->activity->title,
                'slug' => $this->activity->slug,
                'category' => $this->activity->category->name,
                'average_rating' => (float) $this->activity->average_rating,
                'reviews_count' => $this->activity->reviews_count,
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
