<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a provider operates. Required to submit a provider request; nullable because providers that already exist have
 * never been asked.
 *
 * Safe to run on any database. A fresh install already has these columns (they are in the create_providers_table
 * migration), and a database migrated BEFORE that edit does not: editing a migration that has already run changes
 * nothing, so this one adds the columns where they are missing and does nothing where they are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'city')) {
                $table->string('city', 255)->nullable()->after('address');
            }

            if (! Schema::hasColumn('providers', 'area')) {
                $table->string('area', 255)->nullable()->after('city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            foreach (['city', 'area'] as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
