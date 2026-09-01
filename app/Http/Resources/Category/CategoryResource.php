<?php

namespace App\Http\Resources\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => $this->image ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->image) : null,
            'sort_order' => $this->sort_order,
        ];
    }
}
