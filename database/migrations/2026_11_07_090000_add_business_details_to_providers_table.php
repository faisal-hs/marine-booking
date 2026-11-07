<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider application collects beyond the name and place: the kind of business, the marina it works from, and a business
 * phone and email (not the phone the person signs in with). All nullable: providers that exist have never been asked.
 *
 * Safe to run on any database: each column is added only where it is missing.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'business_type' => 120,
        'marina_name' => 255,
        'contact_phone' => 30,
        'contact_email' => 255,
    ];

    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $length) {
                if (! Schema::hasColumn('providers', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            foreach (array_keys(self::COLUMNS) as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
