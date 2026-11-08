<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A kind of document a provider can be asked for. An admin edits these from the dashboard: each is mandatory or optional, and can be switched off. */
class VerificationDocumentType extends Model
{
    use HasUuid;

    protected $fillable = ['key', 'title_en', 'title_ar', 'description_en', 'description_ar', 'is_mandatory', 'is_active', 'sort_order'];

    protected $casts = ['is_mandatory' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /** The title in the language the request is in (Arabic, or English for everything else). */
    public function title(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->title_ar : $this->title_en;
    }

    public function description(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? ($this->description_ar ?: $this->description_en) : ($this->description_en ?: $this->description_ar);
    }
}
