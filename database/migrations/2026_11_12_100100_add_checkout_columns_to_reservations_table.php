<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer app records on a booking: the platform's service fee (the VAT goes in `tax`), who to contact, and an idempotency key so a retried
 * request cannot book twice. A customer's key is unique among their own bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'service_fee')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('service_fee', 12, 2)->default(0)->after('discount');
            $table->string('payment_method', 20)->nullable()->after('source');
            $table->string('contact_name', 120)->nullable()->after('payment_method');
            $table->string('contact_phone', 30)->nullable()->after('contact_name');
            $table->string('contact_email', 190)->nullable()->after('contact_phone');
            $table->string('idempotency_key', 80)->nullable()->after('contact_email');
            $table->unique(['customer_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'service_fee')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropUnique(['customer_id', 'idempotency_key']);
                $table->dropColumn(['service_fee', 'payment_method', 'contact_name', 'contact_phone', 'contact_email', 'idempotency_key']);
            });
        }
    }
};
