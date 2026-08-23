<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Events\PurchaseCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class EvaluatePurchaseAchievementsListener implements ShouldQueue
{
    private const int MAX_ATTEMPTS = 10;

    private const int LOCK_RETRY_DELAY_SECONDS = 1;

    private const int LOCK_LEASE_SECONDS = 60;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(
        private readonly EvaluatePurchaseAchievements $evaluatePurchaseAchievements,
    ) {}

    public function handle(PurchaseCompleted $event): void
    {
        $this->evaluatePurchaseAchievements->handle($event->purchase);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(PurchaseCompleted $event): array
    {
        /*
         * Achievement evaluation for one user does not overlap. A collision is
         * retried after one second, while the lease bounds a lock left behind by
         * a stopped worker. This lock does not guarantee queue order.
         */
        return [
            (new WithoutOverlapping("user:{$event->purchase->user_id}"))
                ->shared()
                ->withPrefix('achievement-progression:')
                ->releaseAfter(self::LOCK_RETRY_DELAY_SECONDS)
                ->expireAfter(self::LOCK_LEASE_SECONDS),
        ];
    }
}
