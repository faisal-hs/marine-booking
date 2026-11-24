<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every notification a gateway sends us (a webhook or a customer's return), once. `event_key` is the gateway's own identity for the event, or the payment and the status it
 * reports, so the same notification arriving twice is the same row: the second is answered from the first. `result` stays null until it has been processed, so one that failed
 * half way is processed again when it is sent again. Guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->id();
                $table->string('gateway', 30);
                $table->string('event_key', 160);
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->string('type', 60)->nullable();
                $table->json('payload')->nullable();
                $table->string('result', 30)->nullable();
                $table->timestamps();

                $table->unique(['gateway', 'event_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
