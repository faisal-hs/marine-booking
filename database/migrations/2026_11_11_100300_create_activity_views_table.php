<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page views of an activity, for the provider's analytics. A view is one VISITOR on one DAY (so refreshing the page does not count again): the
 * visitor is a hash that cannot be turned back into a person (the signed-in account, or the address and browser), and `source` is how they arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_views')) {
            return;
        }

        Schema::create('activity_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->char('visitor_hash', 40);
            $table->date('viewed_on');
            $table->string('source', 20)->default('direct');   // direct, category, recommendations, social
            $table->timestamp('created_at')->nullable();

            $table->unique(['activity_id', 'visitor_hash', 'viewed_on']);
            $table->index(['activity_id', 'viewed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_views');
    }
};
