<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the wallet needs on a provider: how their commission is worked out (a percentage of each booking or a fixed amount per booking, set per
 * provider and the same for all their activities), room for a fixed amount in commission_rate (it held only up to 999.99 as a percentage), and the
 * bank account their withdrawals are paid into (stored encrypted). Safe to run on any database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'commission_type')) {
                $table->string('commission_type', 12)->default('percentage')->after('commission_rate');
            }
            if (! Schema::hasColumn('providers', 'bank_account')) {
                $table->text('bank_account')->nullable();
            }
            // The value: a percentage (0 to 100) or an amount in the platform currency, depending on commission_type.
            $table->decimal('commission_rate', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (Schema::hasColumn('providers', 'bank_account')) {
                $table->dropColumn('bank_account');
            }
            if (Schema::hasColumn('providers', 'commission_type')) {
                $table->dropColumn('commission_type');
            }
            $table->decimal('commission_rate', 5, 2)->default(0)->change();
        });
    }
};
