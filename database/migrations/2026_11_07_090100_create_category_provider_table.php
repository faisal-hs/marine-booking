<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The categories a provider works in: many to many, because one provider can run diving trips and yacht charters. (An ACTIVITY still
 * belongs to one category.) Deleting a provider or a category removes only the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('category_provider')) {
            return;
        }

        Schema::create('category_provider', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['provider_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_provider');
    }
};
