<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer's own view of their own review — includes is_hidden so
 * they can see if it was moderated, which the public-facing
 * PublicReviewResource never needs to expose (a hidden review is simply
 * absent from that listing entirely).
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'activity' => [
                'id' => $this->activity->id,
                'uuid' => $this->activity->uuid,
                'title' => $this->activity->title,
            ],
            'rating' => $this->rating,
            'comment' => $this->comment,
            'is_hidden' => $this->is_hidden,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
