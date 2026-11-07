<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's request to be paid out. The amount is reserved in the wallet the moment it is created. An admin pays it by hand and records the
 * outcome here; nothing is transferred by the system. The bank account is copied in (encrypted) so the payout goes where it was asked, even if the
 * provider changes the account afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('withdrawals')) {
            return;
        }

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->string('currency', 3);
            // pending, processing, paid, failed, rejected, cancelled
            $table->string('status', 20)->default('pending')->index();
            $table->string('idempotency_key', 100);
            $table->text('bank_snapshot')->nullable();
            $table->string('payout_reference')->nullable();
            $table->text('status_reason')->nullable();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
