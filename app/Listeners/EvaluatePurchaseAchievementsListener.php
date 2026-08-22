<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Events\PurchaseCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

final class EvaluatePurchaseAchievementsListener implements ShouldQueue
{
    public function __construct(
        private readonly EvaluatePurchaseAchievements $evaluatePurchaseAchievements,
    ) {}

    public function handle(PurchaseCompleted $event): void
    {
        $this->evaluatePurchaseAchievements->handle($event->purchase);
    }
}
