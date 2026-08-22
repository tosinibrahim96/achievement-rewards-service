<?php

declare(strict_types=1);

namespace App\Actions\Badges;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;

final readonly class UnlockBadge
{
    public function handle(User $user, Badge $badge, UserAchievement $trigger): ?UserBadge
    {
        $userBadge = UserBadge::query()->createOrFirst(
            [
                'user_id' => $user->id,
                'badge_id' => $badge->id,
            ],
            [
                'triggered_by_user_achievement_id' => $trigger->id,
                'correlation_id' => $trigger->correlation_id,
                'unlocked_at' => now(),
            ],
        );

        return $userBadge->wasRecentlyCreated ? $userBadge : null;
    }
}
