<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds Saudi Arabia cities in English and Arabic.
 *
 * Cities are grouped approximately by administrative region.
 * Activities without a city are distributed across the seeded cities.
 * Published activities are featured approximately once every seven activities.
 */
class CitySeeder extends Seeder
{
    private const CITIES = [
        // Riyadh Region
        ['Riyadh', 'الرياض'],
        ['Al Kharj', 'الخرج'],
        ['Diriyah', 'الدرعية'],
        ['Dawadmi', 'الدوادمي'],
        ['Al Majmaah', 'المجمعة'],
        ['Al Zulfi', 'الزلفي'],
        ['Shaqra', 'شقراء'],
        ['Afif', 'عفيف'],
        ['Wadi Al Dawasir', 'وادي الدواسر'],
        ['Layla', 'ليلى'],
        ['Hotat Bani Tamim', 'حوطة بني تميم'],
        ['Thadiq', 'ثادق'],
        ['Rumah', 'رماح'],
        ['Dhurma', 'ضرما'],
        ['Marat', 'مرات'],
        ['Al Ghat', 'الغاط'],
        ['Huraymila', 'حريملاء'],
        ['Al Quwayiyah', 'القويعية'],
        ['Al Muzahimiyah', 'المزاحمية'],
        ['Al Aflaj', 'الأفلاج'],
        ['As Sulayyil', 'السليل'],
        ['Al Hariq', 'الحريق'],
        ['Nafi', 'نفي'],
        ['Jalajil', 'جلاجل'],
        ['Al Artawiyah', 'الأرطاوية'],

        // Makkah Region
        ['Mecca', 'مكة المكرمة'],
        ['Jeddah', 'جدة'],
        ['Taif', 'الطائف'],
        ['Rabigh', 'رابغ'],
        ['Khulais', 'خليص'],
        ['Al Lith', 'الليث'],
        ['Al Qunfudhah', 'القنفذة'],
        ['Al Jumum', 'الجموم'],
        ['Bahrah', 'بحرة'],
        ['Turbah', 'تربة'],
        ['Ranyah', 'رنية'],
        ['Al Khurma', 'الخرمة'],
        ['Adham', 'أضم'],
        ['Maysan', 'ميسان'],
        ['Al Muwayh', 'المويه'],
        ['Al Ardiyat', 'العرضيات'],
        ['Thuwal', 'ثول'],

        // Madinah Region
        ['Medina', 'المدينة المنورة'],
        ['Yanbu', 'ينبع'],
        ['Al Ula', 'العلا'],
        ['Mahd Al Dhahab', 'مهد الذهب'],
        ['Badr', 'بدر'],
        ['Khaybar', 'خيبر'],
        ['Al Hanakiyah', 'الحناكية'],
        ['Al Ais', 'العيص'],
        ['Wadi Al Faraa', 'وادي الفرع'],

        // Eastern Province
        ['Dammam', 'الدمام'],
        ['Al Khobar', 'الخبر'],
        ['Dhahran', 'الظهران'],
        ['Jubail', 'الجبيل'],
        ['Qatif', 'القطيف'],
        ['Ras Tanura', 'رأس تنورة'],
        ['Abqaiq', 'بقيق'],
        ['Al Hofuf', 'الهفوف'],
        ['Al Mubarraz', 'المبرز'],
        ['Hafar Al Batin', 'حفر الباطن'],
        ['Khafji', 'الخفجي'],
        ['Nairyah', 'النعيرية'],
        ['Al Udayd', 'العديد'],
        ['Salwa', 'سلوى'],
        ['Safwa', 'صفوى'],
        ['Tarout', 'تاروت'],

        // Qassim Region
        ['Buraidah', 'بريدة'],
        ['Unaizah', 'عنيزة'],
        ['Ar Rass', 'الرس'],
        ['Al Bukayriyah', 'البكيرية'],
        ['Al Badayea', 'البدائع'],
        ['Al Mithnab', 'المذنب'],
        ['Riyadh Al Khabra', 'رياض الخبراء'],
        ['Uyun Al Jawa', 'عيون الجواء'],
        ['Ash Shimasiyah', 'الشماسية'],
        ['Al Asyah', 'الأسياح'],
        ['Al Nabhaniyah', 'النبهانية'],

        // Asir Region
        ['Abha', 'أبها'],
        ['Khamis Mushait', 'خميس مشيط'],
        ['Bisha', 'بيشة'],
        ['Muhayil', 'محايل عسير'],
        ['Al Namas', 'النماص'],
        ['Sarat Abidah', 'سراة عبيدة'],
        ['Tathleeth', 'تثليث'],
        ['Ahad Rufaidah', 'أحد رفيدة'],
        ['Bareq', 'بارق'],
        ['Balqarn', 'بلقرن'],
        ['Rijal Almaa', 'رجال ألمع'],
        ['Al Majardah', 'المجاردة'],
        ['Tanomah', 'تنومة'],
        ['Dhahran Al Janub', 'ظهران الجنوب'],
        ['Tabalah', 'تبالة'],

        // Tabuk Region
        ['Tabuk', 'تبوك'],
        ['Duba', 'ضباء'],
        ['Al Wajh', 'الوجه'],
        ['Haql', 'حقل'],
        ['Tayma', 'تيماء'],
        ['Umluj', 'أملج'],
        ['Sharma', 'شرما'],

        // Hail Region
        ['Hail', 'حائل'],
        ['Baqaa', 'بقعاء'],
        ['Al Ghazalah', 'الغزالة'],
        ['Shinan', 'الشنان'],
        ['Mawqaq', 'موقق'],
        ['Al Hait', 'الحائط'],

        // Northern Borders Region
        ['Arar', 'عرعر'],
        ['Rafha', 'رفحاء'],
        ['Turaif', 'طريف'],
        ['Al Uwayqilah', 'العويقيلة'],

        // Jazan Region
        ['Jizan', 'جازان'],
        ['Sabya', 'صبيا'],
        ['Abu Arish', 'أبو عريش'],
        ['Samtah', 'صامطة'],
        ['Baish', 'بيش'],
        ['Farasan', 'فرسان'],
        ['Al Darb', 'الدرب'],
        ['Ahad Al Masarihah', 'أحد المسارحة'],
        ['Harub', 'هروب'],
        ['Fifa', 'فيفاء'],
        ['Al Aridhah', 'العارضة'],
        ['Al Tuwal', 'الطوال'],
        ['Damad', 'ضمد'],
        ['Al Reeth', 'الريث'],

        // Najran Region
        ['Najran', 'نجران'],
        ['Sharurah', 'شرورة'],
        ['Habuna', 'حبونا'],
        ['Badr Al Janub', 'بدر الجنوب'],
        ['Yadamah', 'يدمة'],
        ['Thar', 'ثار'],
        ['Khubash', 'خباش'],

        // Al Bahah Region
        ['Al Bahah', 'الباحة'],
        ['Baljurashi', 'بلجرشي'],
        ['Al Mandaq', 'المندق'],
        ['Al Makhwah', 'المخواة'],
        ['Qilwah', 'قلوة'],
        ['Al Aqiq', 'العقيق'],
        ['Ghamid Al Zanad', 'غامد الزناد'],
        ['Dhi Ayn', 'ذي عين'],
        ['Bani Hasan', 'بني حسن'],
        ['Al Hajar', 'الحجرة'],
    ];

    public function run(): void
    {
        foreach (self::CITIES as $i => [$en, $ar]) {
            City::updateOrCreate(
                ['slug' => Str::slug($en)],
                [
                    'name_en' => $en,
                    'name_ar' => $ar,
                    'sort_order' => $i,
                ]
            );
        }

        $ids = City::whereIn(
            'slug',
            array_map(fn($city) => Str::slug($city[0]), self::CITIES)
        )
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            return;
        }

        DB::table('activities')
            ->whereNull('city_id')
            ->orderBy('id')
            ->get(['id', 'status'])
            ->each(function ($activity) use ($ids) {
                DB::table('activities')
                    ->where('id', $activity->id)
                    ->update([
                        'city_id' => $ids[$activity->id % count($ids)],
                        'is_featured' => $activity->status === 'published'
                            && $activity->id % 7 === 0,
                    ]);
            });
    }
}
