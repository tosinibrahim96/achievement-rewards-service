<?php

declare(strict_types=1);

namespace App\Domain\Achievements;

use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Enums\AchievementMetric;
use App\Enums\Currency;
use App\Models\Purchase;
use App\Models\User;

final readonly class LifetimeSpendProgressCalculator implements AchievementProgressCalculator
{
    public function metric(): AchievementMetric
    {
        return AchievementMetric::LifetimeSpend;
    }

    public function progressFor(User $user): int
    {
        return (int) Purchase::query()
            ->whereBelongsTo($user)
            ->where('currency', Currency::Ngn)
            ->sum('amount_minor');
    }
}
