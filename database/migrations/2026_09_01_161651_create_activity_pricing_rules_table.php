<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('activity_booking_option_id')->constrained('activity_booking_options')->cascadeOnDelete();

            $table->enum('participant_type', ['adult', 'child', 'infant'])->nullable();
            $table->decimal('price', 10, 2);

            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();

            // NULL = applies generally to the booking option; a real id =
            // this rule overrides pricing specifically for that one
            // schedule period (e.g. morning price vs. evening price).
            $table->foreignId('schedule_period_id')->nullable()->constrained('activity_schedule_periods')->nullOnDelete();

            $table->string('currency', 3)->default('EGP');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['activity_booking_option_id', 'participant_type', 'is_active'], 'apr_option_type_active_idx');
            $table->index('schedule_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_pricing_rules');
    }
};
