<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class CreateCashbackReward
{
    public function handle(UserBadge $userBadge): CashbackReward
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Cashback reward creation must run inside a database transaction.');
        }

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

        // Wait for payout account changes before choosing the reward status.
        User::query()->whereKey($userBadge->user_id)->lockForUpdate()->firstOrFail();

        return CashbackReward::query()->createOrFirst(
            ['user_badge_id' => $userBadge->id],
            [
                'user_id' => $userBadge->user_id,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'provider_reference' => 'cashback-'.Str::lower((string) Str::ulid()),
                'status' => $this->startingStatusFor($userBadge->user_id),
                'correlation_id' => $userBadge->correlation_id,
            ],
        );
    }

    private function startingStatusFor(int $userId): CashbackRewardStatus
    {
        $hasVerifiedPayoutAccount = PayoutAccount::query()
            ->where('user_id', $userId)
            ->whereNotNull('verified_at')
            ->exists();

        return $hasVerifiedPayoutAccount
            ? CashbackRewardStatus::ReadyForPayout
            : CashbackRewardStatus::AwaitingPayoutAccount;
    }
}
