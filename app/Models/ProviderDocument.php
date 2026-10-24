<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProviderDocument extends Model
{
    use HasUuid;

    protected $fillable = [
        'provider_id',
        'type',
        'title',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
