<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row for each booking a coupon was used on (at most one coupon per booking). It stays even if the booking is cancelled or expires, so the
 * history is whole; a coupon's USED COUNT only counts redemptions whose booking is still alive, so a use is given back when the booking is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coupon_redemptions')) {
            return;
        }

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);                     // as it was when used
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('created_at')->nullable();

            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
