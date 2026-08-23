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

    public int $timeout = 30;

    public int $uniqueFor = 300;

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
        return [
            (new WithoutOverlapping("reward:{$this->cashbackRewardId}"))
                ->shared()
                ->withPrefix('cashback-payment:')
                ->dontRelease()
                ->expireAfter(60),
        ];
    }
}
