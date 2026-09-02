<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('activity_booking_option_id')->constrained('activity_booking_options')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('schedule_period_id')->nullable()->constrained('activity_schedule_periods')->nullOnDelete();

            $table->date('reservation_date');
            $table->json('participants')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'expired', 'completed'])->default('pending');

            // Only meaningful while status = pending — see ReservationCapacityLock
            // and ReservationRepository::consumedCapacity() for how this drives
            // live capacity enforcement without depending on a scheduled job.
            $table->timestamp('hold_expires_at')->nullable();

            // Frozen price snapshot — NEVER recalculated after creation, per
            // spec §32's "pricing snapshot compatibility" requirement.
            $table->string('pricing_model', 20);
            $table->string('currency', 3);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->json('pricing_breakdown');

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The hot-path index — every single reservation attempt runs a
            // capacity-check query filtered on exactly these columns.
            $table->index(['activity_booking_option_id', 'schedule_period_id', 'reservation_date', 'status'], 'res_capacity_lookup_idx');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
