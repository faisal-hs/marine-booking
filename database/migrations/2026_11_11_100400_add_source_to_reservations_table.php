<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How the customer reached the activity they booked (direct, category, recommendations, social): what the provider's booking sources are made of. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservations', 'source')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->string('source', 20)->nullable()->after('currency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'source')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
