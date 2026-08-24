<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cashback\ProcessCashbackPayout;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessCashbackPayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int PROCESS_TIMEOUT_SECONDS = 30;

    private const int UNIQUE_LOCK_SECONDS = 300;

    private const int OVERLAP_LOCK_SECONDS = 60;

    /*
     * Queue locks reduce duplicate jobs. The database check is what prevents a
     * second provider call. The overlap lock lasts longer than the job timeout.
     */
    public int $timeout = self::PROCESS_TIMEOUT_SECONDS;

    public int $uniqueFor = self::UNIQUE_LOCK_SECONDS;

    public function __construct(public int $cashbackRewardId) {}

    public function handle(ProcessCashbackPayout $processCashbackPayout): void
    {
        $processCashbackPayout->handle($this->cashbackRewardId);
    }

    public function uniqueId(): string
    {
        return (string) $this->cashbackRewardId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        /*
         * If another job is handling this reward, delete this duplicate instead of
         * retrying it. The database state still decides whether a payout may start.
         */
        return [
            (new WithoutOverlapping("reward:{$this->cashbackRewardId}"))
                ->shared()
                ->withPrefix('cashback-payout:')
                ->dontRelease()
                ->expireAfter(self::OVERLAP_LOCK_SECONDS),
        ];
    }
}
