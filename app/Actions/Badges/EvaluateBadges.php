<?php

declare(strict_types=1);

namespace App\Actions\Badges;

use App\Models\Badge;
use App\Models\CashbackReward;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class EvaluateBadges
{
    public function __construct(
        private UnlockBadge $unlockBadge,
    ) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $achievementCount = UserAchievement::query()
                ->whereBelongsTo($lockedUser)
                ->count();

            if ($achievementCount === 0) {
                $this->logBadgeResultAfterCommit(
                    userId: $lockedUser->id,
                    triggerUserAchievementId: null,
                    correlationId: null,
                    achievementCount: 0,
                    unlockedBadgeNames: [],
                    newCashbackRewardIds: [],
                );

                return;
            }

            $latestAchievement = UserAchievement::query()
                ->whereBelongsTo($lockedUser)
                ->orderByDesc('unlocked_at')
                ->orderByDesc('id')
                ->firstOrFail();

            $badges = Badge::query()
                ->where('is_active', true)
                ->where('required_achievement_count', '<=', $achievementCount)
                ->orderBy('rank')
                ->orderBy('id')
                ->get();

            $unlockedBadgeNames = [];
            $newUserBadgeIds = [];

            foreach ($badges as $badge) {
                $userBadge = $this->unlockBadge->handle($lockedUser, $badge, $latestAchievement);

                if ($userBadge !== null) {
                    $unlockedBadgeNames[] = $badge->name;
                    $newUserBadgeIds[] = $userBadge->id;
                }
            }

            $newCashbackRewardIds = [];

            if ($newUserBadgeIds !== []) {
                $newCashbackRewards = CashbackReward::query()
                    ->whereIn('user_badge_id', $newUserBadgeIds)
                    ->orderBy('id')
                    ->get(['id']);

                foreach ($newCashbackRewards as $cashbackReward) {
                    $newCashbackRewardIds[] = $cashbackReward->id;
                }
            }

            $this->logBadgeResultAfterCommit(
                userId: $lockedUser->id,
                triggerUserAchievementId: $latestAchievement->id,
                correlationId: $latestAchievement->correlation_id,
                achievementCount: $achievementCount,
                unlockedBadgeNames: $unlockedBadgeNames,
                newCashbackRewardIds: $newCashbackRewardIds,
            );
        });
    }

    /**
     * @param  list<string>  $unlockedBadgeNames
     * @param  list<int>  $newCashbackRewardIds
     */
    private function logBadgeResultAfterCommit(
        int $userId,
        ?int $triggerUserAchievementId,
        ?string $correlationId,
        int $achievementCount,
        array $unlockedBadgeNames,
        array $newCashbackRewardIds,
    ): void {
        $logDetails = [
            'user_id' => $userId,
            'trigger_user_achievement_id' => $triggerUserAchievementId,
            'correlation_id' => $correlationId,
            'achievement_count' => $achievementCount,
            'unlocked_badge_names' => $unlockedBadgeNames,
            'cashback_reward_ids' => $newCashbackRewardIds,
        ];

        DB::afterCommit(function () use ($logDetails, $correlationId): void {
            try {
                Context::scope(
                    fn () => Log::info('badge.evaluation.completed', $logDetails),
                    ['correlation_id' => $correlationId],
                );
            } catch (Throwable $exception) {
                $this->reportLogFailure($exception);
            }
        });
    }

    private function reportLogFailure(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // The badge changes are already committed; a reporting failure must not change the result.
        }
    }
}
