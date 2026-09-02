<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_capacity_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_booking_option_id')->constrained('activity_booking_options')->cascadeOnDelete();

            // Sentinel 0 = "no specific period" — NOT nullable, deliberately.
            // MySQL treats NULL as distinct from NULL in unique indexes, which
            // would let two concurrent "no period" lock rows exist for the
            // same date, defeating the whole purpose of this table (see class
            // doc comment on ReservationCapacityLock).
            $table->unsignedBigInteger('schedule_period_id')->default(0);

            $table->date('reservation_date');
            $table->timestamps();

            $table->unique(['activity_booking_option_id', 'schedule_period_id', 'reservation_date'], 'res_lock_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_capacity_locks');
    }
};
