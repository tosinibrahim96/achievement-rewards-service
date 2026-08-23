<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cashback\DispatchActionableCashbackRewards;
use Illuminate\Console\Command;

final class DispatchActionableCashbackRewardsCommand extends Command
{
    protected $signature = 'cashback:dispatch-actionable';

    protected $description = 'Dispatch queued payment work for actionable cashback rewards';

    public function handle(DispatchActionableCashbackRewards $dispatchActionableCashbackRewards): int
    {
        $candidates = $dispatchActionableCashbackRewards->dispatchForAllUsers();

        $this->info("Requested processing for {$candidates} actionable cashback reward(s).");

        return self::SUCCESS;
    }
}
