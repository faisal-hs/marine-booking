<?php

namespace App\Http\Resources\Provider;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'business_name' => $this->business_name,
            'slug' => $this->slug,
            'bio' => $this->bio,
            'logo' => $this->logo,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'verification_status' => $this->verification_status,
            'status' => $this->user->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
