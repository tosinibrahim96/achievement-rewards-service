<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Illuminate\Support\Str;

final class BadgeTestData
{
    public static function giveAchievements(User $user, int $count): void
    {
        $achievements = Achievement::query()
            ->join('achievement_groups', 'achievement_groups.id', '=', 'achievements.achievement_group_id')
            ->orderBy('achievement_groups.sort_order')
            ->orderBy('achievements.threshold')
            ->select('achievements.*')
            ->limit($count)
            ->get();

        foreach ($achievements as $index => $achievement) {
            UserAchievement::factory()->for($user)->for($achievement)->create([
                'correlation_id' => (string) Str::ulid(),
                'unlocked_at' => now()->addSeconds($index),
            ]);
        }
    }

    /** @return list<string> */
    public static function codesFor(User $user): array
    {
        return UserBadge::query()
            ->whereBelongsTo($user)
            ->join('badges', 'badges.id', '=', 'user_badges.badge_id')
            ->orderBy('user_badges.id')
            ->pluck('badges.code')
            ->all();
    }
}
