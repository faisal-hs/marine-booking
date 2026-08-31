<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ActivityFeatureValue extends Model
{
    use HasUuid;

    protected $fillable = [
        'activity_id',
        'feature_definition_id',
        'value_text',
        'value_number',
        'value_boolean',
        'value_json',
    ];

    protected $casts = [
        'value_boolean' => 'boolean',
        'value_json' => 'array',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function featureDefinition()
    {
        return $this->belongsTo(FeatureDefinition::class);
    }

    /**
     * Bridges the four typed storage columns into a single clean value,
     * so nothing outside this model needs to know about the multi-column
     * storage detail. Assumes featureDefinition is loaded or lazy-loadable.
     */
    public function getValueAttribute(): mixed
    {
        return match ($this->featureDefinition->type) {
            'text', 'select' => $this->value_text,
            'number' => $this->value_number !== null ? (float) $this->value_number : null,
            'boolean' => $this->value_boolean,
            'multi_select' => $this->value_json,
            default => null,
        };
    }
}
