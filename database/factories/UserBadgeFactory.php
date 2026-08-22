<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserBadge> */
class UserBadgeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'badge_id' => Badge::factory(),
            'triggered_by_user_achievement_id' => null,
            'correlation_id' => (string) Str::ulid(),
            'unlocked_at' => now(),
        ];
    }
}
