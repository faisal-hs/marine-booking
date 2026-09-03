<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_point')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('min_participants')->default(1);
            $table->unsignedInteger('max_participants')->nullable();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->enum('status', ['draft', 'pending_review', 'approved', 'rejected', 'published', 'inactive'])->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider_id', 'status']);
            $table->index(['category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
