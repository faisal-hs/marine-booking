<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The destinations of the demo data, in English and Arabic, and each demo activity placed in one of them (evenly, by its id) with about one in seven
 * featured, so the customer app's home screen and filters have something to show. Only activities without a city are placed, and a city is only made
 * if it is missing, so running it again changes nothing.
 */
class CitySeeder extends Seeder
{
    private const CITIES = [['Jeddah', 'جدة'], ['Yanbu', 'ينبع'], ['Dammam', 'الدمام'], ['Al Khobar', 'الخبر'], ['Jizan', 'جازان'], ['Umluj', 'أملج']];

    public function run(): void
    {
        foreach (self::CITIES as $i => [$en, $ar]) {
            City::firstOrCreate(['slug' => Str::slug($en)], ['name_en' => $en, 'name_ar' => $ar, 'sort_order' => $i]);
        }

        $ids = City::whereIn('slug', array_map(fn ($c) => Str::slug($c[0]), self::CITIES))->orderBy('sort_order')->pluck('id')->all();

        DB::table('activities')->whereNull('city_id')->orderBy('id')->get(['id', 'status'])->each(function ($activity) use ($ids) {
            DB::table('activities')->where('id', $activity->id)->update(['city_id' => $ids[$activity->id % count($ids)], 'is_featured' => $activity->status === 'published' && $activity->id % 7 === 0]);
        });
    }
}
