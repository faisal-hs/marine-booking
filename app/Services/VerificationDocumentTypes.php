<?php

namespace App\Services;

use App\Models\VerificationDocumentType;
use Illuminate\Support\Collection;

/**
 * Which documents a provider can be asked for, and which of them are mandatory: what an admin configured on the dashboard, read when it is needed
 * (never cached, so a change applies on the next request). Applying as a provider needs a file for every ACTIVE MANDATORY type.
 */
class VerificationDocumentTypes
{
    /** @return Collection<int, VerificationDocumentType> the types a provider can upload now, in the admin's order */
    public function active(): Collection
    {
        return VerificationDocumentType::query()->active()->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->active()->pluck('key')->all();
    }

    /** @return list<string> the types an application cannot be sent without */
    public function mandatoryKeys(): array
    {
        return $this->active()->where('is_mandatory', true)->pluck('key')->values()->all();
    }

    /** @return array<string, string> key => title in the language of the request, for EVERY type (a document of a switched-off type keeps its name) */
    public function titles(): array
    {
        return VerificationDocumentType::query()->orderBy('sort_order')->orderBy('id')->get()->mapWithKeys(fn (VerificationDocumentType $t) => [$t->key => $t->title()])->all();
    }

    public function find(string $key): ?VerificationDocumentType
    {
        return VerificationDocumentType::query()->active()->where('key', $key)->first();
    }

    public function isMandatory(string $key): bool
    {
        return in_array($key, $this->mandatoryKeys(), true);
    }
}
