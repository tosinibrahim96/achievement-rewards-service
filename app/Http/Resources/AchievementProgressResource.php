<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Achievements\UserAchievementProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AchievementProgressResource extends JsonResource
{
    /**
     * @return array{
     *     unlocked_achievements: list<string>,
     *     next_available_achievements: list<string>,
     *     current_badge: string|null,
     *     next_badge: string|null,
     *     remaining_to_unlock_next_badge: int
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var UserAchievementProgress $progress */
        $progress = $this->resource;

        return [
            'unlocked_achievements' => $progress->unlockedAchievements,
            'next_available_achievements' => $progress->nextAvailableAchievements,
            'current_badge' => $progress->currentBadge,
            'next_badge' => $progress->nextBadge,
            'remaining_to_unlock_next_badge' => $progress->remainingToUnlockNextBadge,
        ];
    }
}
