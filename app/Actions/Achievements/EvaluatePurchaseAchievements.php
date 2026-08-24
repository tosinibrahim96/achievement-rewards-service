<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Domain\Achievements\AchievementProgressRegistry;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            $unlockedAchievementNames = [];
            $this->logAchievementResultAfterCommit($purchase, $unlockedAchievementNames);
            $groups = AchievementGroup::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            /** @var array<string, int> $progressByMetric */
            $progressByMetric = [];

            foreach ($groups as $group) {
                $metric = $group->metric->value;

                /*
                 * Calculating a metric reads the user's purchases. Several groups
                 * can use the same metric, so calculate it once.
                 */
                if (! array_key_exists($metric, $progressByMetric)) {
                    $progressByMetric[$metric] = $this->progressRegistry
                        ->for($group->metric)
                        ->progressFor($user);
                }

                $progress = $progressByMetric[$metric];
                $achievements = Achievement::query()
                    ->whereBelongsTo($group, 'group')
                    ->where('is_active', true)
                    ->where('threshold', '<=', $progress)
                    ->orderBy('threshold')
                    ->orderBy('id')
                    ->get();

                foreach ($achievements as $achievement) {
                    $userAchievement = $this->unlockAchievement->handle($user, $achievement, $purchase);

                    if ($userAchievement !== null) {
                        $unlockedAchievementNames[] = $achievement->name;
                    }
                }
            }
        });
    }

    /** @param list<string> $unlockedAchievementNames */
    private function logAchievementResultAfterCommit(Purchase $purchase, array &$unlockedAchievementNames): void
    {
        $purchaseDetails = [
            'purchase_id' => $purchase->id,
            'user_id' => $purchase->user_id,
            'correlation_id' => $purchase->correlation_id,
        ];

        /*
         * Set up this after-commit log before sending unlock events so an event
         * failure cannot skip it. The callback reads the final list after commit.
         */
        DB::afterCommit(function () use ($purchaseDetails, &$unlockedAchievementNames): void {
            $logDetails = [
                ...$purchaseDetails,
                'unlocked_count' => count($unlockedAchievementNames),
                'unlocked_achievement_names' => $unlockedAchievementNames,
            ];

            try {
                Context::scope(
                    fn () => Log::info('achievement.evaluation.completed', $logDetails),
                    ['correlation_id' => $logDetails['correlation_id']],
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
            /*
             * The achievement changes are already saved, so a reporting failure
             * must not change the result.
             */
        }
    }
}
