<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\Bulk\BulkPaymentEventSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesSeededBulkDatabase;
use Tests\TestCase;

/**
 * About a quarter of the bulk payments went through Tabby or Tamara, with what those gateways keep (their own reference, the status they last reported, why a failed one failed), and
 * BulkPaymentEventSeeder gives each settled one the notifications that gateway would have sent, so the two stories agree. Mock payments have none; running it again adds nothing.
 */
class BulkPaymentEventSeederTest extends TestCase
{
    use UsesSeededBulkDatabase;

    protected function bulkScale(): float
    {
        return (float) (getenv('BULK_TEST_SCALE') ?: 0.05);
    }

    public function test_about_a_quarter_of_the_payments_went_through_tabby_or_tamara_and_each_keeps_its_gateways_details(): void
    {
        $all = DB::table('payments')->count();
        $tabby = DB::table('payments')->where('gateway', 'tabby')->count();
        $tamara = DB::table('payments')->where('gateway', 'tamara')->count();

        $this->assertGreaterThan(0.15 * $all, $tabby + $tamara);
        $this->assertLessThan(0.35 * $all, $tabby + $tamara);
        $this->assertGreaterThan(0, $tabby);
        $this->assertGreaterThan(0, $tamara);
        $this->assertSame(0, DB::table('payments')->where('gateway', 'tabby')->where('payment_id', 'not like', 'tabby_pay_%')->count());
        $this->assertSame(0, DB::table('payments')->whereIn('gateway', ['tabby', 'tamara'])->whereNull('gateway_status')->count());
        $this->assertSame(0, DB::table('payments')->where('gateway', 'mock')->whereNotNull('gateway_status')->count(), 'The mock keeps none of it');
        $this->assertSame(0, DB::table('payments')->whereIn('gateway', ['tabby', 'tamara'])->where('status', 'failed')->where('failure_code', '!=', 'declined')->count());
        $this->assertSame(0, DB::table('payments')->whereIn('gateway', ['tabby', 'tamara'])->where('status', 'pending')->whereNull('checkout_url')->count(), 'A payment still waiting says where to pay');
    }

    public function test_every_settled_tabby_or_tamara_payment_has_its_notifications_and_nothing_else_has(): void
    {
        $settled = DB::table('payments')->whereIn('gateway', ['tabby', 'tamara'])->where('status', '!=', 'pending')->pluck('id')->all();
        $withEvents = DB::table('payment_events')->whereNotNull('payment_id')->distinct()->pluck('payment_id')->all();

        $this->assertSame([], array_values(array_diff($settled, $withEvents)), 'A settled payment has events');
        $this->assertSame([], array_values(array_diff($withEvents, $settled)), 'Only settled Tabby and Tamara payments have events: not the mock, not one still waiting');
    }

    public function test_the_notifications_tell_the_same_story_as_the_payment(): void
    {
        $rows = DB::table('payment_events as e')->join('payments as p', 'p.id', '=', 'e.payment_id')->get(['e.gateway', 'e.event_key', 'e.type', 'e.result', 'p.gateway as pgateway', 'p.payment_id', 'p.status']);

        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $this->assertSame($row->pgateway, $row->gateway);
            $this->assertSame("{$row->payment_id}:{$row->type}", $row->event_key);
            $this->assertSame($row->status === 'failed' ? 'failed' : 'succeeded', $row->result);
            $this->assertContains($row->type, $row->gateway === 'tabby' ? ['AUTHORIZED', 'CLOSED', 'REJECTED'] : ['order_approved', 'order_captured', 'order_declined']);
        }
        $this->assertSame(0, DB::table('payments as p')->join('payment_events as e', 'e.payment_id', '=', 'p.id')->where('p.status', 'failed')->whereIn('e.type', ['AUTHORIZED', 'CLOSED', 'order_approved', 'order_captured'])->count(), 'A refused payment was never approved');
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $before = DB::table('payment_events')->count();
        DB::beginTransaction();
        try {
            $this->seed(BulkPaymentEventSeeder::class);
            $this->seed(BulkPaymentEventSeeder::class);
            $after = DB::table('payment_events')->count();
        } finally {
            DB::rollBack();
        }

        $this->assertGreaterThan(0, $before);
        $this->assertSame($before, $after);
    }
}
