<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Enums\CashbackRewardStatus;
use App\Models\CashbackReward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class DispatchActionableCashbackRewards
{
    public const DEFAULT_CHUNK_SIZE = 100;

    public function __construct(
        private DispatchCashbackPaymentJob $dispatchCashbackPaymentJob,
    ) {}

    public function dispatchForUser(
        int $userId,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ): int {
        return $this->dispatch($userId, $chunkSize);
    }

    public function dispatchForAllUsers(int $chunkSize = self::DEFAULT_CHUNK_SIZE): int
    {
        return $this->dispatch(null, $chunkSize);
    }

    private function dispatch(?int $userId, int $chunkSize): int
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The cashback reward chunk size must be positive.');
        }

        $candidates = 0;
        $query = CashbackReward::query()
            ->select('cashback_rewards.id')
            ->where('cashback_rewards.status', CashbackRewardStatus::AwaitingPayoutAccount)
            ->whereNull('cashback_rewards.provider')
            ->whereDoesntHave('payoutAttempts')
            ->whereHas(
                'user.payoutAccount',
                static fn (Builder $query): Builder => $query->whereNotNull('verified_at'),
            );

        if ($userId !== null) {
            $query->where('cashback_rewards.user_id', $userId);
        }

        $dispatchCashbackPaymentJob = $this->dispatchCashbackPaymentJob;

        $query->chunkById(
            $chunkSize,
            static function (Collection $rewards) use (&$candidates, $dispatchCashbackPaymentJob): void {
                foreach ($rewards as $reward) {
                    $dispatchCashbackPaymentJob->handle($reward->id);
                    $candidates++;
                }
            },
            'cashback_rewards.id',
            'id',
        );

        return $candidates;
    }
}
