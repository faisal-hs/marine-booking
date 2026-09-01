<?php

namespace App\Http\Resources\Schedule;

use App\Models\ActivitySchedulePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchedulePeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'day_of_week' => ActivitySchedulePeriod::dayNameFromNumber($this->day_of_week),
            'start_time' => \Illuminate\Support\Carbon::parse($this->start_time)->format('H:i'),
            'end_time' => \Illuminate\Support\Carbon::parse($this->end_time)->format('H:i'),
            'crosses_midnight' => $this->crosses_midnight,
            'price' => (float) $this->price,
            'instructions' => $this->instructions,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
        ];
    }
}
