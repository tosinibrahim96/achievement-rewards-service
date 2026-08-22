<?php

declare(strict_types=1);

namespace App\Contracts\Achievements;

use App\Enums\AchievementMetric;
use App\Models\User;

interface AchievementProgressCalculator
{
    public function metric(): AchievementMetric;

    public function progressFor(User $user): int;
}
