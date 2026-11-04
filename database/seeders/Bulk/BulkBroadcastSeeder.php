<?php

namespace Database\Seeders\Bulk;

use Database\Seeders\Bulk\Concerns\BulkPools;
use Illuminate\Support\Facades\DB;

/**
 * Announcements staff sent in the past: 80% sent, 10% still drafts, 10% failed. Delivery counts are plausible (a
 * sent announcement reached most of an audience that was then somewhat smaller than today's), but the
 * per-user notifications of historical announcements are not generated.
 * Volume at scale 1: 600, across all three audiences.
 * Prerequisites: BulkUserSeeder (the staff who wrote them), BulkCustomerSeeder, BulkProviderSeeder.
 */
class BulkBroadcastSeeder extends BulkSeeder
{
    use BulkPools;

    protected function seed(): void
    {
        $this->needs('users', 'BulkUserSeeder', fn ($q) => $q->where('phone', 'like', self::STAFF_PHONE_PREFIX.'%'));
        $this->needs('customers', 'BulkCustomerSeeder');

        $staff = DB::table('users')->where('phone', 'like', self::STAFF_PHONE_PREFIX.'%')->pluck('id')->all();
        $customerUsers = DB::table('customers')->orderBy('id')->limit(500)->pluck('user_id')->all();
        $audience = ['customers' => DB::table('customers')->count(), 'providers' => DB::table('providers')->where('verification_status', 'verified')->count()];
        $texts = $this->broadcastTexts();

        $this->insert('broadcasts', (function () use ($staff, $customerUsers, $audience, $texts) {
            for ($i = 0, $count = $this->scaled(600); $i < $count; $i++) {
                [$title, $body] = $texts[$i % count($texts)];
                $kind = $this->weighted(['customers' => 60, 'providers' => 25, 'user' => 15]);
                $status = $this->weighted(['sent' => 80, 'draft' => 10, 'failed' => 10]);
                $created = $this->daysAgo(1, 400);
                $reached = $kind === 'user' ? 1 : max(1, (int) round($audience[$kind] * $this->rand(60, 100) / 100));

                yield [
                    'uuid' => $this->uuid('broadcast', $i),
                    'title' => $title.($i >= count($texts) ? ' #'.($i + 1) : ''),
                    'body' => $body,
                    'audience' => $kind,
                    'recipient_user_id' => $kind === 'user' ? $this->pick($customerUsers) : null,
                    'status' => $status,
                    'recipients_count' => $status === 'draft' ? 0 : $reached,
                    'delivered_count' => $status === 'sent' ? max(1, (int) round($reached * $this->rand(97, 100) / 100)) : 0,
                    'created_by' => $this->pick($staff),
                    'sent_at' => $status === 'sent' ? $this->ts($created->copy()->addMinutes($this->rand(1, 120))) : null,
                    'failed_reason' => $status === 'failed' ? $this->pick(['The queue worker lost its connection', 'Push service unavailable', 'Timed out while sending']) : null,
                    'created_at' => $this->ts($created),
                    'updated_at' => $this->ts($created),
                ];
            }
        })());
    }
}
