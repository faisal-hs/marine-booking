<?php

namespace Database\Seeders\Bulk;

use Illuminate\Support\Facades\DB;

/**
 * The capacity-lock rows. The app creates one the first time anyone tries to book an option on a departure and a
 * day, and keeps it as the row it locks while checking capacity. Here there is one for every distinct option,
 * departure and day among the reservations, written with a single set-based statement.
 *
 * Volume at scale 1: about 143,000.
 * Prerequisites: BulkReservationSeeder.
 */
class BulkReservationCapacityLockSeeder extends BulkSeeder
{
    protected function seed(): void
    {
        $this->needs('reservations', 'BulkReservationSeeder');

        $stamp = $this->ts($this->now);

        // A booking with no particular departure is stored with period 0, as the app does.
        $slots = DB::table('reservations')
            ->selectRaw('activity_booking_option_id, COALESCE(schedule_period_id, 0) as schedule_period_id, reservation_date, ? as created_at, ? as updated_at', [$stamp, $stamp])
            ->distinct();

        $this->created += DB::table('reservation_capacity_locks')->insertOrIgnoreUsing(
            ['activity_booking_option_id', 'schedule_period_id', 'reservation_date', 'created_at', 'updated_at'],
            $slots
        );
    }
}
