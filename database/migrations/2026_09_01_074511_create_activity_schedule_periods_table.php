<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_schedule_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();

            // 0 = Sunday ... 6 = Saturday, matching Carbon::dayOfWeek / PHP date('w').
            $table->unsignedTinyInteger('day_of_week');

            $table->time('start_time');
            $table->time('end_time');

            // Derived at write-time (ScheduleService), stored rather than
            // computed on every read — see ActivitySchedulePeriod model for
            // the single place this is calculated from start/end.
            $table->boolean('crosses_midnight')->default(false);


            $table->text('instructions')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['activity_id', 'day_of_week', 'status'], 'asp_activity_day_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_schedule_periods');
    }
};
