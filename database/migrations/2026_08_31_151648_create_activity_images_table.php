<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_images', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();

            $table->index(['activity_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_images');
    }
};
