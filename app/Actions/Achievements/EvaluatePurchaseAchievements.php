<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Domain\Achievements\AchievementProgressRegistry;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class EvaluatePurchaseAchievements
{
    public function __construct(
        private AchievementProgressRegistry $progressRegistry,
        private UnlockAchievement $unlockAchievement,
    ) {}

    public function handle(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase): void {
            $user = User::query()->lockForUpdate()->findOrFail($purchase->user_id);
            $groups = AchievementGroup::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($groups as $group) {
                $progress = $this->progressRegistry->for($group->metric)->progressFor($user);
                $achievements = Achievement::query()
                    ->whereBelongsTo($group, 'group')
                    ->where('is_active', true)
                    ->where('threshold', '<=', $progress)
                    ->orderBy('threshold')
                    ->orderBy('id')
                    ->get();

                foreach ($achievements as $achievement) {
                    $this->unlockAchievement->handle($user, $achievement, $purchase);
                }
            }
        });
    }
}
