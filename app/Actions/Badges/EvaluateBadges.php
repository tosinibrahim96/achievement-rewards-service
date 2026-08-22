<?php

declare(strict_types=1);

namespace App\Actions\Badges;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Support\Facades\DB;

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
                return;
            }

            $trigger = UserAchievement::query()
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

            foreach ($badges as $badge) {
                $this->unlockBadge->handle($lockedUser, $badge, $trigger);
            }
        });
    }
}
