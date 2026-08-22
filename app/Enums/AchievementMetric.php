<?php

declare(strict_types=1);

namespace App\Enums;

enum AchievementMetric: string
{
    case PurchaseCount = 'purchase_count';
    case LifetimeSpend = 'lifetime_spend';
}
