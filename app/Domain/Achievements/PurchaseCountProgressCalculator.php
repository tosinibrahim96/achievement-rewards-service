<?php

declare(strict_types=1);

namespace App\Domain\Achievements;

use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Enums\AchievementMetric;
use App\Models\Purchase;
use App\Models\User;

final readonly class PurchaseCountProgressCalculator implements AchievementProgressCalculator
{
    public function metric(): AchievementMetric
    {
        return AchievementMetric::PurchaseCount;
    }

    public function progressFor(User $user): int
    {
        return Purchase::query()->whereBelongsTo($user)->count();
    }
}
