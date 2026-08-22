<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Events\AchievementUnlocked;
use App\Models\Achievement;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;

final readonly class UnlockAchievement
{
    public function handle(User $user, Achievement $achievement, Purchase $purchase): ?UserAchievement
    {
        $unlock = UserAchievement::query()->createOrFirst(
            [
                'user_id' => $user->id,
                'achievement_id' => $achievement->id,
            ],
            [
                'triggered_by_purchase_id' => $purchase->id,
                'correlation_id' => $purchase->correlation_id,
                'unlocked_at' => now(),
            ],
        );

        if (! $unlock->wasRecentlyCreated) {
            return null;
        }

        AchievementUnlocked::dispatch($achievement->name, $user);

        return $unlock;
    }
}
