<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer talking to a provider about an activity. One conversation per customer, provider and activity. A message is written by the customer or by
 * the provider; `read_at` is when the OTHER side read it, which is what the unread counts are made of.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
                $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'provider_id', 'activity_id']);
                $table->index(['customer_id', 'last_message_at']);
            });
        }

        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->string('sender_type', 10);              // customer, provider
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['conversation_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
