<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sex of the person behind an account: `male` or `female`, or nothing (nobody is asked to say). It is on the user, so a customer, a provider and a staff member all have
 * it in one place, and it is indexed because the admin lists and the provider's customers list can be filtered by it. Guarded, so it can be run on a database that has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'sex')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('sex', 10)->nullable()->after('email');
                $table->index('sex');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'sex')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['sex']);
                $table->dropColumn('sex');
            });
        }
    }
};
