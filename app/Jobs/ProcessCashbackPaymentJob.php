<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cashback\ProcessCashbackPayment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessCashbackPaymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int PROCESS_TIMEOUT_SECONDS = 30;

    private const int UNIQUE_LOCK_SECONDS = 300;

    private const int OVERLAP_LOCK_SECONDS = 60;

    /*
     * Queue uniqueness reduces duplicate dispatches; the database claim remains
     * the correctness guard. The overlap lease outlives the worker timeout.
     */
    public int $timeout = self::PROCESS_TIMEOUT_SECONDS;

    public int $uniqueFor = self::UNIQUE_LOCK_SECONDS;

    public function __construct(public int $cashbackRewardId) {}

    public function handle(ProcessCashbackPayment $processCashbackPayment): void
    {
        $processCashbackPayment->handle($this->cashbackRewardId);
    }

    public function uniqueId(): string
    {
        return (string) $this->cashbackRewardId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        /*
         * A duplicate that reaches this busy reward is deleted instead of retried.
         * Normally the lock holder is already processing the same durable reward;
         * the database claim remains the final guard against duplicate provider work.
         */
        return [
            (new WithoutOverlapping("reward:{$this->cashbackRewardId}"))
                ->shared()
                ->withPrefix('cashback-payment:')
                ->dontRelease()
                ->expireAfter(self::OVERLAP_LOCK_SECONDS),
        ];
    }
}
