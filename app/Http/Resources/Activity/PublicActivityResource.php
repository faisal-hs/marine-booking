<?php

namespace App\Http\Resources\Activity;

use App\Http\Resources\ActivityFeature\ActivityFeatureResource;
use App\Http\Resources\ActivityImage\ActivityImageResource;
use App\Http\Resources\ActivityInclusion\ActivityInclusionResource;
use App\Http\Resources\ActivityOption\ActivityOptionResource;
use App\Http\Resources\ActivityRequirement\ActivityRequirementResource;
use App\Http\Resources\BookingOption\PublicActivityBookingOptionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public-facing activity detail response. Deliberately a SEPARATE
 * class from the provider-facing ActivityResource (used by the combined
 * create/update endpoint) rather than one shared Resource with
 * context-aware branching — a Resource shouldn't need to know "am I
 * being rendered for the owning provider or an anonymous customer".
 *
 * Per spec §35: exposes only ACTIVE booking options, each with only its
 * own active pricing rules. Does not expose 'overrides' (provider-only
 * date-blackout configuration) or raw 'schedule' periods at all — public
 * consumers get resolved bookability through the dedicated
 * /availability endpoint (Module 07), never the raw weekly template.
 */
class PublicActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => [
                'id' => $this->category->id,
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
            ],
            'location' => $this->location,
            'meeting_point' => $this->meeting_point,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'duration_minutes' => $this->duration_minutes,
            'min_participants' => $this->min_participants,
            'max_participants' => $this->max_participants,
            'min_age' => $this->min_age,
            'images' => ActivityImageResource::collection($this->images),
            'features' => ActivityFeatureResource::collection($this->featureValues),
            'options' => ActivityOptionResource::collection($this->options),
            'requirements' => ActivityRequirementResource::collection($this->requirements),
            'inclusions' => ActivityInclusionResource::collection($this->inclusions),
            'booking_options' => PublicActivityBookingOptionResource::collection(
                $this->bookingOptions->where('is_active', true)->values()
            ),
        ];
    }
}
