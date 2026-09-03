<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReviewResource extends JsonResource
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
            'customer' => [
                'id' => $this->customer->id,
                'uuid' => $this->customer->uuid,
                'name' => $this->customer->user->name,
                'email' => $this->customer->user->email,
            ],
            'rating' => $this->rating,
            'comment' => $this->comment,
            'is_hidden' => $this->is_hidden,
            'hidden_reason' => $this->hidden_reason,
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
