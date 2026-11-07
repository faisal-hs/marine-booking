<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who cancelled a booking: the customer, the provider, an admin or the system, and which account did it. Cancellations made before this
 * existed keep a null source (nobody recorded it). Safe to run on any database: each column is added only where it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'cancellation_source')) {
                $table->string('cancellation_source', 20)->nullable()->after('cancellation_reason');
            }
            if (! Schema::hasColumn('reservations', 'cancelled_by_user_id')) {
                $table->foreignId('cancelled_by_user_id')->nullable()->after('cancellation_source')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'cancelled_by_user_id')) {
                $table->dropConstrainedForeignId('cancelled_by_user_id');
            }
            if (Schema::hasColumn('reservations', 'cancellation_source')) {
                $table->dropColumn('cancellation_source');
            }
        });
    }
};
