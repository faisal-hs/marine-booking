<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The kinds of document a provider can be asked for, edited by an admin from the dashboard: each is mandatory or optional, and can be switched off.
 * They used to be a list in config/providers.php. The migration creates the same eight, with the same three mandatory, so nothing changes until an
 * admin changes it. Safe to run on any database.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        ['commercial_registration', 'Commercial registration', 'السجل التجاري', true],
        ['safety_certification', 'Safety certification', 'شهادة السلامة', true],
        ['insurance_certificate', 'Insurance certificate', 'شهادة التأمين', true],
        ['business_license', 'Business licence', 'رخصة النشاط التجاري', false],
        ['identity_document', 'Identity document', 'وثيقة الهوية', false],
        ['professional_certification', 'Professional certification', 'شهادة مهنية', false],
        ['insurance', 'Insurance', 'التأمين', false],
        ['other', 'Other document', 'مستند آخر', false],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('verification_document_types')) {
            Schema::create('verification_document_types', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                // What providers and the API call it: lower case letters, digits and underscores. Never changes once documents use it.
                $table->string('key', 40)->unique();
                $table->string('title_en', 120);
                $table->string('title_ar', 120);
                $table->text('description_en')->nullable();
                $table->text('description_ar')->nullable();
                $table->boolean('is_mandatory')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        foreach (self::DEFAULTS as $i => [$key, $en, $ar, $mandatory]) {
            if (! DB::table('verification_document_types')->where('key', $key)->exists()) {
                DB::table('verification_document_types')->insert([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'key' => $key, 'title_en' => $en, 'title_ar' => $ar, 'is_mandatory' => $mandatory,
                    'is_active' => true, 'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_document_types');
    }
};
