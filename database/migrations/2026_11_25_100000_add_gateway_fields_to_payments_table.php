<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a payment through an online gateway (Tabby, Tamara) needs to remember: where the customer pays (`checkout_url`), until when (`expires_at`), what the gateway last said
 * (`gateway_status`), and WHY a payment did not succeed (`failure_code`: declined, cancelled, expired, mismatch, late_payment, error). The `status` enum is untouched. Guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'checkout_url')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->text('checkout_url')->nullable()->after('provider_reference');
                $table->timestamp('expires_at')->nullable()->after('checkout_url');
                $table->string('gateway_status', 40)->nullable()->after('expires_at');
                $table->string('failure_code', 30)->nullable()->after('failure_reason');
                $table->index(['gateway', 'status', 'created_at'], 'payments_gateway_status_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'checkout_url')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_gateway_status_index');
                $table->dropColumn(['checkout_url', 'expires_at', 'gateway_status', 'failure_code']);
            });
        }
    }
};
