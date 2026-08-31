<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasUuid, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image',
        'status',
        'sort_order',
    ];

    public function activities()
    {
        // Populated in Module 05 — declared now so the relationship
        // is discoverable from the model that owns the foreign key's "one" side.
        return $this->hasMany(Activity::class);
    }
}
