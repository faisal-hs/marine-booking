<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's coupons. The provider funds the discount: the customer pays less and the provider earns on what the customer paid. There are no
 * platform-funded coupons. `code` is kept in capitals and is unique PER PROVIDER (a booking is for one activity, so its provider is always known).
 * An activity list that is empty means every activity of the provider. Coupons are deactivated, never deleted, so old bookings keep their history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('type', 10);                           // percent, fixed
                $table->decimal('value', 12, 2);                      // a percentage (0 to 100) or an amount in the platform currency
                $table->unsignedInteger('usage_limit')->nullable();   // null: unlimited
                $table->decimal('max_discount', 12, 2)->nullable();   // the most a percent coupon can take off
                $table->decimal('min_order_amount', 12, 2)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['provider_id', 'code']);
                $table->index(['provider_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('activity_coupon')) {
            Schema::create('activity_coupon', function (Blueprint $table) {
                $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
                $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
                $table->primary(['coupon_id', 'activity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_coupon');
        Schema::dropIfExists('coupons');
    }
};
