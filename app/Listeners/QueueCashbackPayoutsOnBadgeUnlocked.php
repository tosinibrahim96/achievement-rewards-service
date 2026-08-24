<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Cashback\QueueCashbackPayouts;
use App\Events\BadgeUnlocked;
use Illuminate\Contracts\Queue\ShouldQueue;

final class QueueCashbackPayoutsOnBadgeUnlocked implements ShouldQueue
{
    private const int MAX_ATTEMPTS = 10;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(
        private readonly QueueCashbackPayouts $queueCashbackPayouts,
    ) {}

    public function handle(BadgeUnlocked $event): void
    {
        $this->queueCashbackPayouts->queueForUser($event->user->id);
    }
}
