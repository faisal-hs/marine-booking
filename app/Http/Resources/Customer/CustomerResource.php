<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'sex' => $this->user->sex,
            'phone' => $this->user->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'preferred_language' => $this->preferred_language,
            'city' => $this->city,
            'status' => $this->user->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
