<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Badge> */
class BadgeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'code' => str($name)->slug()->toString(),
            'name' => str($name)->title()->toString(),
            'required_achievement_count' => fake()->unique()->numberBetween(1, 10_000),
            'rank' => fake()->unique()->numberBetween(1, 30_000),
            'is_active' => true,
        ];
    }
}
