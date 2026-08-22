<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AchievementMetric;
use App\Models\AchievementGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AchievementGroup> */
class AchievementGroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'code' => str($name)->slug()->toString(),
            'name' => str($name)->title()->toString(),
            'metric' => AchievementMetric::PurchaseCount,
            'sort_order' => fake()->unique()->numberBetween(1, 30_000),
            'is_active' => true,
        ];
    }
}
