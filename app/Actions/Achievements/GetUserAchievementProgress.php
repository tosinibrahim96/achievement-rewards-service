<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Data\Achievements\AchievementProgressRow;
use App\Data\Achievements\UserAchievementProgress;
use App\Models\Badge;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use UnexpectedValueException;

final readonly class GetUserAchievementProgress
{
    public function handle(User $user): UserAchievementProgress
    {
        /*
         * Read the badge first. If an unlock happens between queries, we may show
         * new achievements with the previous badge, but never a new badge before
         * the achievements that earned it.
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

    /** @return Collection<int, AchievementProgressRow> */
    private function achievementRowsFor(User $user): Collection
    {
        /*
         * Start with achievement definitions because the response needs their
         * names. The left join only marks the ones this user unlocked; it does not
         * load full unlock records and their related models.
         *
         * Keep unlocked achievements visible even if disabled. Suggest only
         * active achievements from active groups. One query returns the rows used
         * for both lists.
         */
        return DB::table('achievements')
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
            ])
            ->map(
                fn (stdClass $row): AchievementProgressRow => $this->makeAchievementRow($row),
            );
    }

    private function makeAchievementRow(stdClass $row): AchievementProgressRow
    {
        /*
         * The query builder returns objects with no declared property types. Check
         * each value here before using it.
         */
        $groupId = $row->achievement_group_id;
        $name = $row->name;
        $isActive = $row->is_active;
        $groupIsActive = $row->group_is_active;
        $userAchievementId = $row->user_achievement_id;

        if (! is_int($groupId)
            || ! is_string($name)
            || ! is_bool($isActive)
            || ! is_bool($groupIsActive)
            || (! is_int($userAchievementId) && $userAchievementId !== null)) {
            throw new UnexpectedValueException(
                'The achievement progress query returned an unexpected row.',
            );
        }

        return new AchievementProgressRow(
            groupId: $groupId,
            name: $name,
            isActive: $isActive,
            groupIsActive: $groupIsActive,
            isUnlocked: $userAchievementId !== null,
        );
    }

    /**
     * @param  Collection<int, AchievementProgressRow>  $achievementRows
     * @return array{unlocked: list<string>, next: list<string>}
     */
    private function achievementNames(Collection $achievementRows): array
    {
        $unlockedNames = [];
        $nextNames = [];
        $groupsWithNextAchievement = [];

        foreach ($achievementRows as $achievement) {
            if ($achievement->isUnlocked) {
                $unlockedNames[] = $achievement->name;

                continue;
            }

            if (! $achievement->isActive
                || ! $achievement->groupIsActive
                || isset($groupsWithNextAchievement[$achievement->groupId])) {
                continue;
            }

            $nextNames[] = $achievement->name;
            $groupsWithNextAchievement[$achievement->groupId] = true;
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
