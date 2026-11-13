<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buy-now-pay-later methods a provider accepts for an activity. Both are off until the provider turns them on: card and the other usual methods
 * need no choice, and these two cost the provider money, so it is theirs to decide for each activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('activities', 'allows_tabby')) {
            return;
        }

        Schema::table('activities', function (Blueprint $table) {
            $table->boolean('allows_tabby')->default(false)->after('is_featured');
            $table->boolean('allows_tamara')->default(false)->after('allows_tabby');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('activities', 'allows_tabby')) {
            Schema::table('activities', fn (Blueprint $t) => $t->dropColumn(['allows_tabby', 'allows_tamara']));
        }
    }
};
