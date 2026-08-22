<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Models\CashbackReward;
use App\Models\UserBadge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/** @extends Factory<CashbackReward> */
class CashbackRewardFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_badge_id' => UserBadge::factory(),
            'user_id' => static function (array $attributes): int {
                $userBadgeId = $attributes['user_badge_id'] ?? null;

                if (! is_int($userBadgeId)) {
                    throw new LogicException('A persisted user badge is required to create a reward factory record.');
                }

                return UserBadge::query()->findOrFail($userBadgeId)->user_id;
            },
            'amount_minor' => 30_000,
            'currency' => Currency::Ngn,
            'provider_reference' => 'cashback-'.Str::lower((string) Str::ulid()),
            'status' => CashbackRewardStatus::AwaitingPayoutAccount,
            'correlation_id' => static function (array $attributes): string {
                $userBadgeId = $attributes['user_badge_id'] ?? null;

                if (! is_int($userBadgeId)) {
                    throw new LogicException('A persisted user badge is required to create a reward factory record.');
                }

                return UserBadge::query()->findOrFail($userBadgeId)->correlation_id;
            },
            'paid_at' => null,
            'last_attempted_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ];
    }
}
