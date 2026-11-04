<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 120);
            $table->text('body');
            // Who it goes to: every customer, every provider, or one named account.
            $table->enum('audience', ['customers', 'providers', 'user']);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            // draft -> sending -> sent (or failed). Only a draft can be edited or sent.
            $table->enum('status', ['draft', 'sending', 'sent', 'failed'])->default('draft');
            // Fixed when the send starts; delivered_count is how many were handed to the notification
            // system (each then goes out in-app and by push through the queue).
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('failed_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
