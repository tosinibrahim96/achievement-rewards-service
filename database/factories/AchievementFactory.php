<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\AchievementGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Achievement> */
class AchievementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'achievement_group_id' => AchievementGroup::factory(),
            'code' => str($name)->slug()->toString(),
            'name' => str($name)->title()->toString(),
            'threshold' => fake()->unique()->numberBetween(1, 1_000_000),
            'sort_order' => fake()->unique()->numberBetween(1, 30_000),
            'is_active' => true,
        ];
    }
}
