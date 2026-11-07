<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The default currency of a pricing rule row was EGP in the database itself. It is now the platform currency (config/platform.php, SAR).
 * Only the DEFAULT for rows made from now on changes: no existing row is touched, converted or relabelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_pricing_rules', function (Blueprint $table) {
            $table->string('currency', 3)->default(config('platform.currency'))->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_pricing_rules', function (Blueprint $table) {
            $table->string('currency', 3)->default('EGP')->change();
        });
    }
};
