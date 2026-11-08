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
            'city' => $this->city,
            'area' => $this->area,
            // What the provider application collects (null until the provider has applied through the provider app).
            'business_type' => $this->business_type,
            'marina_name' => $this->marina_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'category_ids' => $this->categories()->pluck('categories.id')->all(),
            // Critical information the provider asked to change, waiting for an admin (null when nothing is). The live values above are unchanged meanwhile.
            'pending_profile_change' => $this->pendingChangeData(),
            // The three documents a provider request needs: the latest of each, or null.
            ...collect(app(\App\Services\VerificationDocumentTypes::class)->mandatoryKeys())->mapWithKeys(function (string $type) {
                $document = $this->documentOfType($type);

                return [$type => $document ? (new ProviderDocumentResource($document))->resolve() : null];
            })->all(),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'verification_status' => $this->verification_status,
            'rejection_reason' => $this->rejection_reason,
            'status' => $this->user->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array{uuid: string, requested_at: string, changes: array<string, array{current: mixed, requested: mixed}>}|null */
    private function pendingChangeData(): ?array
    {
        $change = $this->pendingProfileChange()->first();

        if (! $change) {
            return null;
        }

        return [
            'uuid' => $change->uuid,
            'requested_at' => $change->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'changes' => collect($change->requested_changes)->map(fn (array $c) => ['current' => $c['from'], 'requested' => $c['to']])->all(),
        ];
    }
}
