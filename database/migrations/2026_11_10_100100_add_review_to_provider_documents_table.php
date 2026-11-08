<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uploading and approving are separate steps: every document now has a review status, who decided and when, a note, an optional expiry date, and
 * whether a newer version replaced it (it is kept, not deleted, so the record an approval was based on survives).
 *
 * Documents that already exist were uploaded before this had a meaning: those of a provider an admin verified are approved (that is what the
 * verification was based on), the rest are pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('provider_documents', 'status')) {
            Schema::table('provider_documents', function (Blueprint $table) {
                $table->string('status', 12)->default('pending')->after('size_bytes');   // pending, approved, rejected
                $table->text('review_note')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->date('expires_at')->nullable();
                $table->timestamp('superseded_at')->nullable();

                $table->index(['provider_id', 'type', 'superseded_at']);
                $table->index('status');
            });

            DB::table('provider_documents')->whereIn('provider_id', DB::table('providers')->where('verification_status', 'verified')->select('id'))
                ->update(['status' => 'approved', 'reviewed_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('provider_documents', 'status')) {
            Schema::table('provider_documents', function (Blueprint $table) {
                $table->dropIndex(['provider_id', 'type', 'superseded_at']);
                $table->dropIndex(['status']);
                $table->dropConstrainedForeignId('reviewed_by_user_id');
                $table->dropColumn(['status', 'review_note', 'reviewed_at', 'expires_at', 'superseded_at']);
            });
        }
    }
};
