<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purely a dedup ledger, not a business entity — its UNIQUE constraint
 * is the authoritative idempotency guard (matching the same
 * "DB constraint as the real guard, not a check-then-act race" lesson
 * ReservationCapacityLock established in Module 09), preventing the
 * same domain event from ever notifying the same recipient twice, even
 * under concurrent dispatch attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatch_log', function (Blueprint $table) {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('event_key');
            $table->timestamp('created_at')->nullable();

            $table->unique(['notifiable_type', 'notifiable_id', 'event_key'], 'notif_dispatch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatch_log');
    }
};
