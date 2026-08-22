<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserAchievement> */
class UserAchievementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'achievement_id' => Achievement::factory(),
            'triggered_by_purchase_id' => null,
            'correlation_id' => (string) Str::ulid(),
            'unlocked_at' => now(),
        ];
    }
}
