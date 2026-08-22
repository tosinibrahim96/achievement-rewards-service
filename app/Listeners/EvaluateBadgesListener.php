<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Badges\EvaluateBadges;
use App\Events\AchievementUnlocked;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class EvaluateBadgesListener implements ShouldQueue
{
    public int $tries = 10;

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
        return [
            (new WithoutOverlapping("user:{$event->user->id}"))
                ->shared()
                ->withPrefix('badge-progression:')
                ->releaseAfter(1)
                ->expireAfter(60),
        ];
    }
}
