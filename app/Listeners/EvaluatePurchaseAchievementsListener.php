<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Events\PurchaseCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class EvaluatePurchaseAchievementsListener implements ShouldQueue
{
    public int $tries = 10;

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
        return [
            (new WithoutOverlapping("user:{$event->purchase->user_id}"))
                ->shared()
                ->withPrefix('achievement-progression:')
                ->releaseAfter(1)
                ->expireAfter(60),
        ];
    }
}
