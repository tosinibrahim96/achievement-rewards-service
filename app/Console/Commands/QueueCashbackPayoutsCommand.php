<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cashback\QueueCashbackPayouts;
use Illuminate\Console\Command;

final class QueueCashbackPayoutsCommand extends Command
{
    protected $signature = 'cashback:queue-payouts';

    protected $description = 'Queue payout work for ready cashback rewards';

    public function handle(QueueCashbackPayouts $queueCashbackPayouts): int
    {
        $queued = $queueCashbackPayouts->queueForAllUsers();

        $this->info("Queued {$queued} cashback payout job(s).");

        return self::SUCCESS;
    }
}
