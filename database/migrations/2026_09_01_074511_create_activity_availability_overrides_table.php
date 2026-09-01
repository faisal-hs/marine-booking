<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();

            $table->date('date_from');
            $table->date('date_to');

            $table->boolean('is_unavailable')->default(false);

            $table->decimal('price', 10, 2)->nullable();
            $table->text('instructions')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->index(['activity_id', 'date_from', 'date_to'], 'aao_activity_date_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_availability_overrides');
    }
};
