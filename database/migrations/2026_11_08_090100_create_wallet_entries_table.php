<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet ledger: every movement of a provider's money, one signed row each (a credit is positive, a debit negative). Balances are sums of
 * these rows, never recomputed from bookings on every request. A row is never edited; a correction is a new row. `reference` is unique, so
 * posting the same thing twice is impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_entries')) {
            return;
        }

        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('withdrawal_id')->nullable()->index();
            // booking_income, platform_fee, refund, withdrawal_reserve, withdrawal_release, adjustment
            $table->string('type', 30)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            // When the money can be withdrawn: after the waiting period for booking money, null (at once) for the rest.
            $table->timestamp('available_at')->nullable()->index();
            // The day the trip took place (booking money), to report by month and year.
            $table->date('occurred_on')->nullable()->index();
            $table->string('description');
            $table->string('reference')->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
    }
};
