<?php

namespace App\Console\Commands;

use App\Services\Wallet\LedgerService;
use Illuminate\Console\Command;

class SyncWalletCommand extends Command
{
    protected $signature = 'wallet:sync';

    protected $description = 'Post the completed bookings that are not in the provider wallets yet (safe to run any number of times)';

    public function handle(LedgerService $ledger): int
    {
        $this->info("Posted {$ledger->syncAll()} completed booking(s) to the provider wallets.");

        return self::SUCCESS;
    }
}
