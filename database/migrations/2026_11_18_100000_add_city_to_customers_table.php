<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The city a customer is in: free text, up to 120 characters, the same as a provider's own `city`. Optional (a customer who registered without one has none), and sent with
 * the verification code request when registering. Guarded, so it can be run on a database that already has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'city')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('city', 120)->nullable()->after('preferred_language');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'city')) {
            Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('city'));
        }
    }
};
