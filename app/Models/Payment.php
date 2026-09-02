<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasUuid;

    protected $attributes = [
        'status' => 'pending',
        'refunded_amount' => 0,
    ];

    protected $fillable = [
        'reservation_id',
        'gateway',
        'payment_id',
        'provider_reference',
        'amount',
        'currency',
        'status',
        'failure_reason',
        'refunded_amount',
        'paid_at',
        'raw_payload',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->reference_id)) {
                $payment->reference_id = static::generateUniqueReferenceId();
            }
        });
    }

    /**
     * Our own human-facing reference — distinct from 'uuid' (internal,
     * used for route-model binding like every other model in this app)
     * and distinct from 'payment_id'/'provider_reference' (the gateway's
     * own identifiers). Meant to be shown to the customer and usable as
     * a merchant reference passed TO the gateway.
     */
    protected static function generateUniqueReferenceId(): string
    {
        do {
            $candidate = 'PAY-' . strtoupper(Str::random(10));
        } while (static::where('reference_id', $candidate)->exists());

        return $candidate;
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
