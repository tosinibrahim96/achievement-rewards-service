<?php

declare(strict_types=1);

namespace App\Data\Achievements;

final readonly class UserAchievementProgress
{
    /**
     * @param  list<string>  $unlockedAchievements
     * @param  list<string>  $nextAvailableAchievements
     */
    public function __construct(
        public array $unlockedAchievements,
        public array $nextAvailableAchievements,
        public ?string $currentBadge,
        public ?string $nextBadge,
        public int $remainingToUnlockNextBadge,
    ) {}
}
