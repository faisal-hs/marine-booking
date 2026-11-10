<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cities (destinations) an activity can be in, and two things the customer app needs on an activity: its city and whether an admin features it.
 * Cities that providers already wrote in their profile are created from them, and each activity takes its provider's city (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name_en', 120);
                $table->string('name_ar', 120);
                $table->string('slug', 140)->unique();
                $table->string('image')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('activities', 'city_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->foreignId('city_id')->nullable()->after('category_id')->constrained('cities')->nullOnDelete();
                $table->boolean('is_featured')->default(false)->after('status');
                $table->index(['status', 'city_id']);
            });
        }

        // The cities providers already named, and each activity in its provider's city.
        $names = DB::table('providers')->whereNotNull('city')->where('city', '!=', '')->distinct()->pluck('city')->map(fn ($c) => trim($c))->filter()->unique(fn ($c) => mb_strtolower($c));

        foreach ($names as $i => $name) {
            if (! DB::table('cities')->whereRaw('LOWER(name_en) = ?', [mb_strtolower($name)])->exists()) {
                DB::table('cities')->insert(['uuid' => (string) Str::uuid(), 'name_en' => $name, 'name_ar' => $name, 'slug' => Str::slug($name) ?: 'city-'.($i + 1), 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('cities')->get() as $city) {
            DB::table('activities')->whereNull('city_id')->whereIn('provider_id', DB::table('providers')->whereRaw('LOWER(TRIM(city)) = ?', [mb_strtolower($city->name_en)])->select('id'))->update(['city_id' => $city->id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activities', 'city_id')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropIndex(['status', 'city_id']);
                $table->dropConstrainedForeignId('city_id');
                $table->dropColumn('is_featured');
            });
        }
        Schema::dropIfExists('cities');
    }
};
