<?php

namespace App\Http\Resources\ActivityImage;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ActivityImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'url' => Storage::disk('public')->url($this->path),
            'is_cover' => $this->is_cover,
            'sort_order' => $this->sort_order,
        ];
    }
}
