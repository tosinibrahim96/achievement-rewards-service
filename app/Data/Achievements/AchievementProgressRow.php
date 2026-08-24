<?php

declare(strict_types=1);

namespace App\Data\Achievements;

final readonly class AchievementProgressRow
{
    public function __construct(
        public int $groupId,
        public string $name,
        public bool $isActive,
        public bool $groupIsActive,
        public bool $isUnlocked,
    ) {}
}
