<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's request to change critical profile information (the legal business name and so on). It does not take effect until an
 * admin approves it: the live profile keeps its current values meanwhile. Each row keeps what was asked, from what, and the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_profile_changes')) {
            return;
        }

        Schema::create('provider_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // { "business_name": { "from": "Old name", "to": "New name" } }
            $table->json('requested_changes');
            // pending, approved, rejected, superseded (replaced by a newer request), cancelled (the provider went back to the current value)
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profile_changes');
    }
};
