<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a provider wants to be told things: by email, by SMS, by WhatsApp, and whether they want news and offers. Email and news and offers are on by default and SMS
 * and WhatsApp are off, the same as a customer's own preferences (customers.notify_email, notify_sms, notify_marketing). Guarded, so it can be run on a database that already has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('providers', 'notify_email')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->boolean('notify_email')->default(true);
                $table->boolean('notify_sms')->default(false);
                $table->boolean('notify_whatsapp')->default(false);
                $table->boolean('notify_news_and_offers')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('providers', 'notify_email')) {
            Schema::table('providers', fn (Blueprint $table) => $table->dropColumn(['notify_email', 'notify_sms', 'notify_whatsapp', 'notify_news_and_offers']));
        }
    }
};
