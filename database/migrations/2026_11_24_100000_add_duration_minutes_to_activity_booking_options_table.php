<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking option booked by TIME (`per_hour`) is configured by the provider in MINUTES: `min_duration_minutes` is the shortest a booking can be AND the step it grows by (a booking is a
 * whole number of those blocks), and the option's price is the price of ONE such block; `max_duration_minutes` is the longest (optional). Both null: the option keeps working as it
 * always did, in whole hours (blocks of 60 minutes, `min_duration` and `max_duration` in hours). Guarded, so it can be run on a database that has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_booking_options', 'min_duration_minutes')) {
            Schema::table('activity_booking_options', function (Blueprint $table) {
                $table->unsignedSmallInteger('min_duration_minutes')->nullable()->after('duration_unit');
                $table->unsignedSmallInteger('max_duration_minutes')->nullable()->after('min_duration_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity_booking_options', 'min_duration_minutes')) {
            Schema::table('activity_booking_options', function (Blueprint $table) {
                $table->dropColumn(['min_duration_minutes', 'max_duration_minutes']);
            });
        }
    }
};
