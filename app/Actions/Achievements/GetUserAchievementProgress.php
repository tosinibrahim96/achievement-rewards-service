<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Data\Achievements\UserAchievementProgress;
use App\Models\Achievement;
use App\Models\Badge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class GetUserAchievementProgress
{
    public function handle(User $user): UserAchievementProgress
    {
        // Read the badge first. If data changes between queries, achievements may be newer
        // than the badge, which is the delayed-badge case handled below.
        $currentBadge = Badge::query()
            ->select('badges.*')
            ->join('user_badges', 'user_badges.badge_id', '=', 'badges.id')
            ->where('user_badges.user_id', $user->id)
            ->orderByDesc('badges.rank')
            ->orderByDesc('badges.code')
            ->first();

        // Keep inactive earned achievements visible and include them in the badge count.
        $unlockedAchievements = $this->orderedAchievements()
            ->join(
                'user_achievements',
                'user_achievements.achievement_id',
                '=',
                'achievements.id',
            )
            ->where('user_achievements.user_id', $user->id)
            ->get();

        /** @var array<int, true> $unlockedIds */
        $unlockedIds = [];
        /** @var list<string> $unlockedAchievementNames */
        $unlockedAchievementNames = [];

        foreach ($unlockedAchievements as $achievement) {
            $unlockedIds[$achievement->id] = true;
            $unlockedAchievementNames[] = $achievement->name;
        }

        $activeAchievements = $this->orderedAchievements()
            ->where('achievement_groups.is_active', true)
            ->where('achievements.is_active', true)
            ->get();

        /** @var array<int, true> $groupsWithNextAchievement */
        $groupsWithNextAchievement = [];
        $nextAchievementNames = [];

        foreach ($activeAchievements as $achievement) {
            if (isset($unlockedIds[$achievement->id])) {
                continue;
            }

            if (isset($groupsWithNextAchievement[$achievement->achievement_group_id])) {
                continue;
            }

            $nextAchievementNames[] = $achievement->name;
            $groupsWithNextAchievement[$achievement->achievement_group_id] = true;
        }

        $nextBadgeQuery = Badge::query()
            ->where('is_active', true);

        if ($currentBadge !== null) {
            $nextBadgeQuery->where('rank', '>', $currentBadge->rank);
        }

        $nextBadge = $nextBadgeQuery
            ->orderBy('rank')
            ->orderBy('code')
            ->first();

        $remainingToUnlockNextBadge = $nextBadge === null
            ? 0
            : max(0, $nextBadge->required_achievement_count - $unlockedAchievements->count());

        return new UserAchievementProgress(
            unlockedAchievements: $unlockedAchievementNames,
            nextAvailableAchievements: $nextAchievementNames,
            currentBadge: $currentBadge?->name,
            nextBadge: $nextBadge?->name,
            remainingToUnlockNextBadge: $remainingToUnlockNextBadge,
        );
    }

    /** @return Builder<Achievement> */
    private function orderedAchievements(): Builder
    {
        return Achievement::query()
            ->select('achievements.*')
            ->join(
                'achievement_groups',
                'achievement_groups.id',
                '=',
                'achievements.achievement_group_id',
            )
            ->orderBy('achievement_groups.sort_order')
            ->orderBy('achievement_groups.code')
            ->orderBy('achievements.sort_order')
            ->orderBy('achievements.code');
    }
}
