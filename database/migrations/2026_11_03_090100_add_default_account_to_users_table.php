<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which account (customer or provider) a phone signs into by default, for a person who has both. Null means "the
 * account they registered as" (user_type).
 *
 * Safe to run on any database: a fresh install already has the column (it is in the create_users_table migration), and a
 * database migrated before that edit does not, so this adds it only where it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'default_account')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('default_account', 20)->nullable()->after('user_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'default_account')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('default_account');
            });
        }
    }
};
