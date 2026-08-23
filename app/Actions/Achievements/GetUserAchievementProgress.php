<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Data\Achievements\UserAchievementProgress;
use App\Models\Badge;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type ProgressAchievementRow object{
 *     id: int,
 *     achievement_group_id: int,
 *     name: string,
 *     is_active: bool,
 *     group_is_active: bool,
 *     user_achievement_id: int|null
 * }
 * @phpstan-type ProgressAchievementNames array{
 *     unlocked: list<string>,
 *     next: list<string>
 * }
 */
final readonly class GetUserAchievementProgress
{
    public function handle(User $user): UserAchievementProgress
    {
        /*
         * Read the badge first. If another worker saves progress between queries,
         * this keeps the supported result: newer achievements with an older badge.
         */
        $currentBadge = $this->currentBadgeFor($user);
        $achievementNames = $this->achievementNames(
            $this->achievementRowsFor($user),
        );
        $nextBadge = $this->nextBadgeAfter($currentBadge);
        $remainingToUnlockNextBadge = $nextBadge === null
            ? 0
            : max(
                0,
                $nextBadge->required_achievement_count - count($achievementNames['unlocked']),
            );

        return new UserAchievementProgress(
            unlockedAchievements: $achievementNames['unlocked'],
            nextAvailableAchievements: $achievementNames['next'],
            currentBadge: $currentBadge?->name,
            nextBadge: $nextBadge?->name,
            remainingToUnlockNextBadge: $remainingToUnlockNextBadge,
        );
    }

    private function currentBadgeFor(User $user): ?Badge
    {
        return Badge::query()
            ->select(['badges.name', 'badges.rank'])
            ->join('user_badges', 'user_badges.badge_id', '=', 'badges.id')
            ->where('user_badges.user_id', $user->id)
            ->orderByDesc('badges.rank')
            ->orderByDesc('badges.code')
            ->first();
    }

    /** @return Collection<int, ProgressAchievementRow> */
    private function achievementRowsFor(User $user): Collection
    {
        /*
         * UserAchievement rows are audit history, while this response needs the
         * achievement definitions. This left join adds only an unlock marker and
         * avoids loading award models and their relationships.
         *
         * Earned definitions remain visible after they are disabled. Unearned
         * suggestions require both the group and definition to still be active.
         * The grouped OR keeps both rules in one ordered database read.
         */
        /** @var Collection<int, ProgressAchievementRow> $rows */
        $rows = DB::table('achievements')
            ->join(
                'achievement_groups',
                'achievement_groups.id',
                '=',
                'achievements.achievement_group_id',
            )
            ->leftJoin(
                'user_achievements',
                function (JoinClause $join) use ($user): void {
                    $join->on(
                        'user_achievements.achievement_id',
                        '=',
                        'achievements.id',
                    )->where('user_achievements.user_id', $user->id);
                },
            )
            ->where(function (Builder $query): void {
                $query->whereNotNull('user_achievements.id')
                    ->orWhere(function (Builder $query): void {
                        $query->where('achievement_groups.is_active', true)
                            ->where('achievements.is_active', true);
                    });
            })
            ->orderBy('achievement_groups.sort_order')
            ->orderBy('achievement_groups.code')
            ->orderBy('achievements.sort_order')
            ->orderBy('achievements.code')
            ->get([
                'achievements.id',
                'achievements.achievement_group_id',
                'achievements.name',
                'achievements.is_active',
                'achievement_groups.is_active as group_is_active',
                'user_achievements.id as user_achievement_id',
            ]);

        return $rows;
    }

    /**
     * @param  Collection<int, ProgressAchievementRow>  $achievementRows
     * @return ProgressAchievementNames
     */
    private function achievementNames(Collection $achievementRows): array
    {
        $unlockedNames = [];
        $nextNames = [];
        /** @var array<int, true> $groupsWithNextAchievement */
        $groupsWithNextAchievement = [];

        foreach ($achievementRows as $achievement) {
            if ($achievement->user_achievement_id !== null) {
                $unlockedNames[] = $achievement->name;

                continue;
            }

            if (! $achievement->is_active
                || ! $achievement->group_is_active
                || isset($groupsWithNextAchievement[$achievement->achievement_group_id])) {
                continue;
            }

            $nextNames[] = $achievement->name;
            $groupsWithNextAchievement[$achievement->achievement_group_id] = true;
        }

        return [
            'unlocked' => $unlockedNames,
            'next' => $nextNames,
        ];
    }

    private function nextBadgeAfter(?Badge $currentBadge): ?Badge
    {
        $query = Badge::query()->where('is_active', true);

        if ($currentBadge !== null) {
            $query->where('rank', '>', $currentBadge->rank);
        }

        return $query
            ->select(['name', 'required_achievement_count'])
            ->orderBy('rank')
            ->orderBy('code')
            ->first();
    }
}
