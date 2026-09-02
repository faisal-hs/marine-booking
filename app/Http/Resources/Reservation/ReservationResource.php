<?php

namespace App\Http\Resources\Reservation;

use App\Models\ActivitySchedulePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ReservationResource extends JsonResource
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
            'booking_option' => [
                'id' => $this->bookingOption->id,
                'uuid' => $this->bookingOption->uuid,
                'name' => $this->bookingOption->name,
                'booking_model' => $this->bookingOption->booking_model->value,
            ],
            'schedule_period' => $this->when($this->schedulePeriod !== null, fn() => [
                'id' => $this->schedulePeriod->id,
                'uuid' => $this->schedulePeriod->uuid,
                'day_of_week' => ActivitySchedulePeriod::dayNameFromNumber($this->schedulePeriod->day_of_week),
                'start_time' => Carbon::parse($this->schedulePeriod->start_time)->format('H:i'),
                'end_time' => Carbon::parse($this->schedulePeriod->end_time)->format('H:i'),
            ]),
            'reservation_date' => $this->reservation_date->toDateString(),
            'participants' => $this->participants,
            'quantity' => $this->quantity,
            'duration' => $this->duration,
            'status' => $this->status->value,
            'hold_expires_at' => $this->when($this->status->value === 'pending', $this->hold_expires_at?->toIso8601String()),
            'pricing_model' => $this->pricing_model,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'pricing_breakdown' => $this->pricing_breakdown,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
