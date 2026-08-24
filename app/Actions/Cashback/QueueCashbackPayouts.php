<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Enums\CashbackRewardStatus;
use App\Models\CashbackReward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final readonly class QueueCashbackPayouts
{
    public const DEFAULT_CHUNK_SIZE = 100;

    public function __construct(
        private QueueCashbackPayout $queueCashbackPayout,
    ) {}

    public function queueForUser(
        int $userId,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ): int {
        return $this->queue($userId, $chunkSize);
    }

    public function queueForAllUsers(int $chunkSize = self::DEFAULT_CHUNK_SIZE): int
    {
        return $this->queue(null, $chunkSize);
    }

    private function queue(?int $userId, int $chunkSize): int
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The cashback reward chunk size must be positive.');
        }

        $queued = 0;
        $query = CashbackReward::query()
            ->select('cashback_rewards.id')
            ->where('cashback_rewards.status', CashbackRewardStatus::ReadyForPayout)
            ->whereNull('cashback_rewards.provider')
            ->whereDoesntHave('payout')
            ->whereHas(
                'user.payoutAccount',
                static fn (Builder $query): Builder => $query->whereNotNull('verified_at'),
            );

        if ($userId !== null) {
            $query->where('cashback_rewards.user_id', $userId);
        }

        $queueCashbackPayout = $this->queueCashbackPayout;

        $query->chunkById(
            $chunkSize,
            static function (Collection $rewards) use (&$queued, $queueCashbackPayout): void {
                foreach ($rewards as $reward) {
                    if ($queueCashbackPayout->handle($reward->id)) {
                        $queued++;
                    }
                }
            },
            'cashback_rewards.id',
            'id',
        );

        return $queued;
    }
}
