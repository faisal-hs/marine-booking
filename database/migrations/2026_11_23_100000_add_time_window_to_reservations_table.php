<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A booking by the hour can be made for a WINDOW: from one time to another inside a departure (`starts_at`, `ends_at`, in UTC like every instant here), with its length in minutes
 * (`duration_minutes`). The ordinary `duration` stays what it was (whole hours, rounded up for a window). All three are null for every other booking. Guarded, so it can be run on
 * a database that has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservations', 'starts_at')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dateTime('starts_at')->nullable()->after('duration');
                $table->dateTime('ends_at')->nullable()->after('starts_at');
                $table->unsignedSmallInteger('duration_minutes')->nullable()->after('ends_at');
                $table->index(['activity_booking_option_id', 'starts_at', 'ends_at'], 'reservations_window_lookup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'starts_at')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropIndex('reservations_window_lookup');
                $table->dropColumn(['starts_at', 'ends_at', 'duration_minutes']);
            });
        }
    }
};
