<?php

namespace Database\Seeders\Bulk;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customers who wrote to support: about one in thirty, one to three tickets each, on a topic the app offers; most about one of their own bookings. Open,
 * answered and closed in the proportions of a real inbox, every one written after the customer joined and not in the future. There are no attachments
 * (no files are made). Customers that already have tickets are left alone.
 * Volume at scale 1: about a thousand tickets.
 * Prerequisites: BulkReservationSeeder.
 */
class BulkSupportTicketSeeder extends BulkSeeder
{
    private const MESSAGES = ['I paid but the booking still shows pending.', 'Can I change the date of my booking?', 'I did not receive my refund yet.', 'How do I change my phone number?',
        'The activity was different from the description.', 'The provider did not answer my message.'];

    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder');

        $have = array_flip(DB::table('support_tickets')->distinct()->pluck('customer_id')->all());
        $topics = config('customer_app.support_topics');

        DB::table('customers')->orderBy('id')->get(['id', 'created_at'])->chunk(5000)->each(function ($customers) use ($have, $topics) {
            $this->insert('support_tickets', (function () use ($customers, $have, $topics) {
                foreach ($customers as $customer) {
                    if (isset($have[$customer->id]) || ! $this->stable("ticket:{$customer->id}", 3.3)) {
                        continue;
                    }

                    $bookings = DB::table('reservations')->where('customer_id', $customer->id)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

                    foreach (range(1, $this->rand(1, 3)) as $n) {
                        $joined = Carbon::parse($customer->created_at);
                        $at = $joined->copy()->addMinutes($this->rand(60, max(61, (int) $joined->diffInMinutes($this->now))))->min($this->now);

                        yield ['uuid' => $this->uuid('ticket', "{$customer->id}-{$n}"), 'customer_id' => $customer->id, 'reservation_id' => $bookings && $this->chance(70) ? $this->pick($bookings) : null,
                            'topic' => $this->pick($topics), 'message' => $this->pick(self::MESSAGES), 'attachment_path' => null, 'attachment_name' => null,
                            'status' => $this->weighted(['open' => 40, 'answered' => 35, 'closed' => 25]), 'created_at' => $this->ts($at), 'updated_at' => $this->ts($at)];
                    }
                }
            })());
        });
    }
}
