<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AchievementMetric;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use Illuminate\Database\Seeder;

class AchievementCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $groupDefinition) {
            $group = AchievementGroup::query()->updateOrCreate(
                ['code' => $groupDefinition['code']],
                [
                    'name' => $groupDefinition['name'],
                    'metric' => $groupDefinition['metric'],
                    'sort_order' => $groupDefinition['sort_order'],
                    'is_active' => true,
                ],
            );

            foreach ($groupDefinition['achievements'] as $achievementDefinition) {
                Achievement::query()->updateOrCreate(
                    ['code' => $achievementDefinition['code']],
                    [
                        'achievement_group_id' => $group->id,
                        'name' => $achievementDefinition['name'],
                        'threshold' => $achievementDefinition['threshold'],
                        'sort_order' => $achievementDefinition['sort_order'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     metric: AchievementMetric,
     *     sort_order: positive-int,
     *     achievements: list<array{
     *         code: string,
     *         name: string,
     *         threshold: positive-int,
     *         sort_order: positive-int
     *     }>
     * }>
     */
    private function definitions(): array
    {
        return [
            [
                'code' => 'purchase-count',
                'name' => 'Purchase Count',
                'metric' => AchievementMetric::PurchaseCount,
                'sort_order' => 1,
                'achievements' => [
                    ['code' => 'first-purchase', 'name' => 'First Purchase', 'threshold' => 1, 'sort_order' => 1],
                    ['code' => 'three-purchases', 'name' => '3 Purchases', 'threshold' => 3, 'sort_order' => 2],
                    ['code' => 'five-purchases', 'name' => '5 Purchases', 'threshold' => 5, 'sort_order' => 3],
                    ['code' => 'ten-purchases', 'name' => '10 Purchases', 'threshold' => 10, 'sort_order' => 4],
                    ['code' => 'twenty-five-purchases', 'name' => '25 Purchases', 'threshold' => 25, 'sort_order' => 5],
                ],
            ],
            [
                'code' => 'lifetime-spend',
                'name' => 'Lifetime Spend',
                'metric' => AchievementMetric::LifetimeSpend,
                'sort_order' => 2,
                'achievements' => [
                    ['code' => 'five-thousand-spent', 'name' => 'NGN 5,000 Spent', 'threshold' => 500_000, 'sort_order' => 1],
                    ['code' => 'ten-thousand-spent', 'name' => 'NGN 10,000 Spent', 'threshold' => 1_000_000, 'sort_order' => 2],
                    ['code' => 'twenty-five-thousand-spent', 'name' => 'NGN 25,000 Spent', 'threshold' => 2_500_000, 'sort_order' => 3],
                    ['code' => 'fifty-thousand-spent', 'name' => 'NGN 50,000 Spent', 'threshold' => 5_000_000, 'sort_order' => 4],
                    ['code' => 'one-hundred-thousand-spent', 'name' => 'NGN 100,000 Spent', 'threshold' => 10_000_000, 'sort_order' => 5],
                ],
            ],
        ];
    }
}
