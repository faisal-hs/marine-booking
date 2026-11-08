<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's reply to a customer review: one ACTIVE reply per review. Deleting a reply keeps the row (with when and by whom) as an audit record
 * and frees the review for a new reply. `is_active` is 1 while the reply stands and NULL once it was deleted, so the unique key allows one active
 * reply and any number of deleted ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('review_replies')) {
            return;
        }

        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['review_id', 'is_active']);
            $table->index('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_replies');
    }
};
