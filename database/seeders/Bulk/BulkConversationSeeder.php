<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customers who wrote to a provider: about one customer-and-activity in ten, among the bookings of verified providers, opened some hours before the
 * first booking was made. One conversation per customer, provider and activity (as in the app). The messages come from BulkMessageSeeder, which also
 * sets when each conversation last had one. Running it again adds nothing.
 * Volume at scale 1: about ten thousand conversations.
 * Prerequisites: BulkReservationSeeder.
 */
class BulkConversationSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder');

        $have = [];
        foreach (DB::table('conversations')->get(['customer_id', 'provider_id', 'activity_id']) as $c) {
            $have["{$c->customer_id}-{$c->provider_id}-{$c->activity_id}"] = true;
        }

        $pairs = DB::table('reservations as r')->join('activities as a', 'a.id', '=', 'r.activity_id')->join('providers as p', 'p.id', '=', 'a.provider_id')
            ->where('p.verification_status', 'verified')->whereNull('r.deleted_at')->groupBy('r.customer_id', 'r.activity_id', 'a.provider_id')
            ->orderBy('r.customer_id')->orderBy('r.activity_id')->selectRaw('r.customer_id, r.activity_id, a.provider_id, MIN(r.created_at) AS first_booked')->get();

        $this->insert('conversations', (function () use ($pairs, $have) {
            foreach ($pairs as $pair) {
                if (isset($have["{$pair->customer_id}-{$pair->provider_id}-{$pair->activity_id}"]) || ! $this->stable("conversation:{$pair->customer_id}-{$pair->activity_id}", 10)) {
                    continue;
                }

                $opened = Carbon::parse($pair->first_booked)->subHours($this->rand(1, 72))->min($this->now);

                yield ['customer_id' => $pair->customer_id, 'provider_id' => $pair->provider_id, 'activity_id' => $pair->activity_id, 'last_message_at' => null,
                    'created_at' => $this->ts($opened), 'updated_at' => $this->ts($opened)];
            }
        })());
    }
}
