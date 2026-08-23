<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Cashback\DispatchActionableCashbackRewards;
use App\Events\PayoutAccountVerified;
use Illuminate\Contracts\Queue\ShouldQueue;

final class DispatchCashbackRewardsOnPayoutAccountVerified implements ShouldQueue
{
    public int $tries = 10;

    public function __construct(
        private readonly DispatchActionableCashbackRewards $dispatchActionableCashbackRewards,
    ) {}

    public function handle(PayoutAccountVerified $event): void
    {
        $this->dispatchActionableCashbackRewards->dispatchForUser(
            $event->payoutAccount->user_id,
        );
    }
}
