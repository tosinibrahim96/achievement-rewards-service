<?php

declare(strict_types=1);

namespace App\Actions\Badges;

use App\Actions\Cashback\CreateCashbackReward;
use App\Events\BadgeUnlocked;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Illuminate\Support\Facades\DB;

final readonly class UnlockBadge
{
    public function __construct(
        private CreateCashbackReward $createCashbackReward,
    ) {}

    public function handle(User $user, Badge $badge, UserAchievement $trigger): ?UserBadge
    {
        return DB::transaction(function () use ($user, $badge, $trigger): ?UserBadge {
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

            if (! $userBadge->wasRecentlyCreated) {
                return null;
            }

            $this->createCashbackReward->handle($userBadge);
            BadgeUnlocked::dispatch($badge->name, $user);

            return $userBadge;
        });
    }
}
