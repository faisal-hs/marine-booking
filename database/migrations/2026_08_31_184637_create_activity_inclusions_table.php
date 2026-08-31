<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_inclusions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->enum('type', ['included', 'excluded']);
            $table->string('description');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['activity_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_inclusions');
    }
};
