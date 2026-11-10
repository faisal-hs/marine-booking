<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A user's avatar (kept privately, shown through the app) and a customer's notification preferences. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_path')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('customers', 'notify_email')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('notify_email')->default(true)->after('preferred_language');
                $table->boolean('notify_sms')->default(false)->after('notify_email');
                $table->boolean('notify_marketing')->default(true)->after('notify_sms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'notify_email')) {
            Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['notify_email', 'notify_sms', 'notify_marketing']));
        }
        if (Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('avatar_path'));
        }
    }
};
