<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/** @extends Factory<PayoutAttempt> */
class PayoutAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cashback_reward_id' => CashbackReward::factory()->state([
                'provider' => PaymentProvider::Fake,
                'status' => CashbackRewardStatus::Processing,
                'last_attempted_at' => now(),
            ]),
            'attempt_number' => 1,
            'payout_account_id' => static function (array $attributes): int {
                $reward = self::rewardFrom($attributes);
                $existingAccount = PayoutAccount::query()
                    ->where('user_id', $reward->user_id)
                    ->first();

                return $existingAccount instanceof PayoutAccount
                    ? $existingAccount->id
                    : PayoutAccount::factory()->for($reward->user)->create()->id;
            },
            'provider' => static fn (array $attributes): PaymentProvider => self::accountFrom($attributes)->provider,
            'provider_reference' => static fn (array $attributes): string => self::rewardFrom($attributes)->provider_reference,
            'provider_recipient_code' => static fn (array $attributes): string => self::accountFrom($attributes)->provider_recipient_code,
            'amount_minor' => static fn (array $attributes): int => self::rewardFrom($attributes)->amount_minor,
            'currency' => static fn (array $attributes): Currency => self::rewardFrom($attributes)->currency,
            'status' => PayoutAttemptStatus::Started,
            'provider_transfer_code' => null,
            'provider_http_status' => null,
            'provider_error_code' => null,
            'provider_error_message' => null,
            'provider_latency_ms' => null,
            'observed_balance_minor' => null,
            'succeeded_at' => null,
            'reversed_at' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /** @param array<mixed> $attributes */
    private static function rewardFrom(array $attributes): CashbackReward
    {
        $rewardId = $attributes['cashback_reward_id'] ?? null;

        if (! is_int($rewardId)) {
            throw new LogicException('A persisted cashback reward is required to create a payout attempt factory record.');
        }

        return CashbackReward::query()->findOrFail($rewardId);
    }

    /** @param array<mixed> $attributes */
    private static function accountFrom(array $attributes): PayoutAccount
    {
        $payoutAccountId = $attributes['payout_account_id'] ?? null;

        if (! is_int($payoutAccountId)) {
            throw new LogicException('A persisted payout account is required to create a payout attempt factory record.');
        }

        return PayoutAccount::query()->findOrFail($payoutAccountId);
    }
}
