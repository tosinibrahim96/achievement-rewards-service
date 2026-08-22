<?php

declare(strict_types=1);

namespace App\Enums;

enum TokenAbility: string
{
    case AchievementsRead = 'achievements:read';
    case PayoutAccountsWrite = 'payout-accounts:write';
    case CashbackRewardsRead = 'cashback-rewards:read';
    case PurchasesWrite = 'purchases:write';

    /**
     * @return list<string>
     */
    public static function customerValues(): array
    {
        return [
            self::AchievementsRead->value,
            self::PayoutAccountsWrite->value,
            self::CashbackRewardsRead->value,
        ];
    }
}
