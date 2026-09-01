<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_booking_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            $table->enum('booking_model', ['per_person', 'per_group', 'per_trip', 'per_unit', 'per_hour', 'per_day', 'fixed', 'package']);

            $table->unsignedInteger('min_participants')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();
            $table->unsignedInteger('min_duration')->nullable();
            $table->unsignedInteger('max_duration')->nullable();
            $table->string('duration_unit', 10)->nullable(); // 'hour' | 'day'

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['activity_id', 'slug'], 'abo_activity_slug_unique');
            $table->index(['activity_id', 'is_active'], 'abo_activity_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_booking_options');
    }
};
