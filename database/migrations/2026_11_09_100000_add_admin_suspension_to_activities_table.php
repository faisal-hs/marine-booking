<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin can stop any activity. That suspension is recorded APART from the provider's own pausing (both leave status = inactive), so a provider can
 * never switch a suspended activity back on: only an admin lifting the suspension can. `suspended_from_status` is what the activity was, so lifting
 * puts it back as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            if (! Schema::hasColumn('activities', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->index();
                $table->text('suspension_reason')->nullable();
                $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('suspended_from_status', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            if (Schema::hasColumn('activities', 'suspended_at')) {
                $table->dropConstrainedForeignId('suspended_by_user_id');
                $table->dropColumn(['suspended_at', 'suspension_reason', 'suspended_from_status']);
            }
        });
    }
};
