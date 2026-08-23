<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Cashback\DispatchActionableCashbackRewards;
use App\Events\BadgeUnlocked;
use Illuminate\Contracts\Queue\ShouldQueue;

final class DispatchCashbackRewardsOnBadgeUnlocked implements ShouldQueue
{
    public int $tries = 10;

    public function __construct(
        private readonly DispatchActionableCashbackRewards $dispatchActionableCashbackRewards,
    ) {}

    public function handle(BadgeUnlocked $event): void
    {
        $this->dispatchActionableCashbackRewards->dispatchForUser($event->user->id);
    }
}
