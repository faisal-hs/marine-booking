<?php

namespace App\Http\Resources\Account;

use App\Http\Resources\Provider\ProviderDocumentResource;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where a person stands on becoming a provider: the status, the latest request, what they filled in,
 * and which of the three required documents are on file. Wraps a User.
 *
 * @property User $resource
 */
class ProviderRequestStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $provider = $user->provider;
        $latest = $provider?->latestRequest;
        $required = config('providers.documents.required_types');

        return [
            // none (no provider account) | not_submitted | pending | approved | rejected
            'status' => app(AccountService::class)->providerRequestStatus($user),
            'request' => $latest ? [
                'uuid' => $latest->uuid,
                'status' => $latest->status,
                'source' => $latest->source,
                'submitted_at' => $latest->submitted_at?->toIso8601String(),
                'reviewed_at' => $latest->reviewed_at?->toIso8601String(),
                'rejection_reason' => $latest->rejection_reason,
            ] : null,
            'provider' => $provider ? [
                'business_name' => $provider->business_name,
                'city' => $provider->city,
                'area' => $provider->area,
                'bio' => $provider->bio,
                'address' => $provider->address,
            ] : null,
            'documents' => collect($required)->mapWithKeys(function (string $type) use ($provider) {
                $document = $provider?->documentOfType($type);

                return [$type => $document ? (new ProviderDocumentResource($document))->resolve() : null];
            })->all(),
            'required_documents' => $required,
        ];
    }
}
