<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Our OWN system-generated reference, auto-created when the
            // model is created (see Payment::booted()) — distinct from
            // 'uuid' (internal, used for route-model binding like every
            // other model in this app) and distinct from the gateway's
            // own identifiers below. Meant to be the human-facing
            // reference shown to the customer / passed to the gateway as
            // a merchant reference.
            $table->string('reference_id')->unique();

            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();

            $table->string('gateway', 30);

            // The gateway's own identifier for THIS payment, used to look
            // up payment details directly from the gateway (e.g. Stripe's
            // PaymentIntent id, Paymob's transaction id) — distinct from
            // provider_reference below, since some gateways use separate
            // ids for "the charge attempt" vs "the checkout session".
            $table->string('payment_id')->nullable();

            // The gateway's transaction/session reference returned from
            // charge() — for gateways where this differs from payment_id
            // (e.g. a checkout session id vs. the eventual charge id).
            $table->string('provider_reference')->nullable();

            // Snapshotted from the reservation's own total at the moment
            // payment is initiated — NEVER recalculated, matching the
            // same "frozen snapshot" principle Module 09 established for
            // reservation pricing.
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);

            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->decimal('refunded_amount', 10, 2)->default(0);

            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index('payment_id');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
