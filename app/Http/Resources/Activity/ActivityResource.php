<?php

namespace App\Http\Resources\Activity;

use App\Http\Resources\ActivityFeature\ActivityFeatureResource;
use App\Http\Resources\ActivityImage\ActivityImageResource;
use App\Http\Resources\ActivityInclusion\ActivityInclusionResource;
use App\Http\Resources\ActivityOption\ActivityOptionResource;
use App\Http\Resources\ActivityRequirement\ActivityRequirementResource;
use App\Http\Resources\Override\AvailabilityOverrideResource;
use App\Http\Resources\Schedule\SchedulePeriodResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
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
            'status' => $this->status,
            'rejection_reason' => $this->when($this->status === 'rejected', $this->rejection_reason),
            'images' => ActivityImageResource::collection($this->images),
            'features' => ActivityFeatureResource::collection($this->featureValues),
            'options' => ActivityOptionResource::collection($this->options),
            'requirements' => ActivityRequirementResource::collection($this->requirements),
            'inclusions' => ActivityInclusionResource::collection($this->inclusions),
            'schedule' => SchedulePeriodResource::collection($this->schedulePeriods),
            'overrides' => AvailabilityOverrideResource::collection($this->availabilityOverrides),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
