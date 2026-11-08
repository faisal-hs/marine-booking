<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admins no longer approve activities, so two states can no longer be reached and nothing may be left in them:
 *
 *   pending_review  the provider had asked to publish and was waiting: it gets what that request gives today, live at once or ready and hidden
 *                   until the provider activates it (ACTIVITY_AUTO_PUBLISH);
 *   rejected        back to a draft the provider can fix and publish.
 *
 * Safe to run again: it only touches rows still in those states.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (config('activities.auto_publish')) {
            DB::table('activities')->where('status', 'pending_review')->update(['status' => 'published', 'published_at' => DB::raw("COALESCE(published_at, '{$now->toDateTimeString()}')"), 'rejection_reason' => null]);
        } else {
            DB::table('activities')->where('status', 'pending_review')->update(['status' => 'approved', 'rejection_reason' => null]);
        }

        DB::table('activities')->where('status', 'rejected')->update(['status' => 'draft', 'rejection_reason' => null]);
    }

    public function down(): void
    {
        // The old states cannot be told apart from the ones they became.
    }
};
