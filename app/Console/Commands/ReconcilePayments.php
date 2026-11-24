<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * The safety net under the webhooks. A payment at an online gateway (Tabby, Tamara) that is still waiting a few minutes after it started is asked of the gateway, and settled the
 * way a webhook would settle it: so a webhook that never arrived, or arrived while the gateway was unreachable, does not leave a paid booking unconfirmed or an approval hanging.
 * It looks back three days. A gateway that cannot be reached is skipped and tried again next time.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--minutes= : Only payments started at least this many minutes ago (default: payments.reconcile_after_minutes)}';

    protected $description = 'Checks online payments that are still waiting with the gateway and settles them.';

    public function handle(PaymentService $payments): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('payments.reconcile_after_minutes'));
        $waiting = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereIn('gateway', PaymentGatewayManager::ONLINE)
            ->whereNotNull('payment_id')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->get();

        $settled = $stillWaiting = $unreachable = 0;

        foreach ($waiting as $payment) {
            try {
                $result = $payments->reconcile($payment, 'command');
                $result->status === PaymentStatus::Pending ? $stillWaiting++ : $settled++;
            } catch (PaymentGatewayException) {
                $unreachable++;
            }
        }

        $this->info("Looked at {$waiting->count()} payment(s): {$settled} settled, {$stillWaiting} still waiting, {$unreachable} could not be checked.");

        return self::SUCCESS;
    }
}
