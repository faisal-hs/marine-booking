<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeatureDefinition extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
        'is_required' => false,
    ];

    protected $fillable = [
        'category_id',
        'key',
        'label',
        'type',
        'options',
        'is_required',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function values()
    {
        return $this->hasMany(ActivityFeatureValue::class);
    }
}
