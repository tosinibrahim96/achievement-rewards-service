<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Cashback\DispatchActionableCashbackRewards;
use App\Events\BadgeUnlocked;
use Illuminate\Contracts\Queue\ShouldQueue;

final class DispatchCashbackRewardsOnBadgeUnlocked implements ShouldQueue
{
    private const int MAX_ATTEMPTS = 10;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(
        private readonly DispatchActionableCashbackRewards $dispatchActionableCashbackRewards,
    ) {}

    public function handle(BadgeUnlocked $event): void
    {
        $this->dispatchActionableCashbackRewards->dispatchForUser($event->user->id);
    }
}
