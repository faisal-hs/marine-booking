<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();

            // Nullable and NOT part of the uniqueness constraint — this
            // records WHICH completed reservation qualified the customer
            // to review (useful for admin/audit context), but the actual
            // "one review per activity" rule is enforced independently
            // of any specific reservation, per the confirmed design.
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            // Soft moderation — "admin can hide/remove later" per the
            // confirmed design. Hiding is reversible and keeps the review
            // record intact for audit; a genuine hard-delete is a
            // separate, additional admin action, not this flag.
            $table->boolean('is_hidden')->default(false);
            $table->string('hidden_reason')->nullable();
            $table->timestamp('hidden_at')->nullable();

            $table->timestamps();

            $table->unique(['customer_id', 'activity_id'], 'reviews_one_per_customer_per_activity');
            $table->index(['activity_id', 'is_hidden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
