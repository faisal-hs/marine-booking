<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A destination an activity can be in. */
class City extends Model
{
    use HasUuid;

    protected $fillable = ['name_en', 'name_ar', 'slug', 'image', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    /** The name in the language of the request. */
    public function name(): string
    {
        return app()->getLocale() === 'ar' && $this->name_ar !== '' ? $this->name_ar : $this->name_en;
    }
}
