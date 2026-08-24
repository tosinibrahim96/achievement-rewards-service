<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Badges\EvaluateBadges;
use App\Events\AchievementUnlocked;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class EvaluateBadgesListener implements ShouldQueue
{
    private const int MAX_ATTEMPTS = 10;

    private const int LOCK_RETRY_DELAY_SECONDS = 1;

    private const int LOCK_LEASE_SECONDS = 60;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(
        private readonly EvaluateBadges $evaluateBadges,
    ) {}

    public function handle(AchievementUnlocked $event): void
    {
        $this->evaluateBadges->handle($event->user);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(AchievementUnlocked $event): array
    {
        /*
         * Run one badge check per user at a time. Retry after one second if the lock
         * is busy. The expiry keeps a stopped worker from blocking later jobs
         * forever. Jobs may still run out of queue order.
         */
        return [
            (new WithoutOverlapping("user:{$event->user->id}"))
                ->shared()
                ->withPrefix('badge-progression:')
                ->releaseAfter(self::LOCK_RETRY_DELAY_SECONDS)
                ->expireAfter(self::LOCK_LEASE_SECONDS),
        ];
    }
}
