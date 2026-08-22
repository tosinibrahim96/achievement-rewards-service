<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Models\CashbackReward;
use App\Models\UserBadge;
use Illuminate\Support\Str;
use LogicException;

final readonly class CreateCashbackReward
{
    public function handle(UserBadge $userBadge): CashbackReward
    {
        $amountMinor = config('rewards.badge_cashback_amount_minor');
        $currencyValue = config('rewards.currency');

        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new LogicException('Badge cashback must be configured as a positive integer in minor units.');
        }

        if (! is_string($currencyValue)) {
            throw new LogicException('Badge cashback currency must be configured as a supported currency code.');
        }

        $currency = Currency::tryFrom($currencyValue);

        if ($currency === null) {
            throw new LogicException('Badge cashback currency must be configured as a supported currency code.');
        }

        return CashbackReward::query()->createOrFirst(
            ['user_badge_id' => $userBadge->id],
            [
                'user_id' => $userBadge->user_id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'provider_reference' => 'cashback-'.Str::lower((string) Str::ulid()),
                'status' => CashbackRewardStatus::AwaitingPayoutAccount,
                'correlation_id' => $userBadge->correlation_id,
            ],
        );
    }
}
