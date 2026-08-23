<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Jobs\ProcessCashbackPaymentJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

final readonly class EnqueueCashbackPayment
{
    public function __construct(
        private Repository $cache,
        private Dispatcher $bus,
    ) {}

    public function handle(int $cashbackRewardId): bool
    {
        $job = new ProcessCashbackPaymentJob($cashbackRewardId);
        $uniqueLock = new UniqueLock($this->cache);

        if (! $uniqueLock->acquire($job)) {
            return false;
        }

        try {
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $uniqueLock->release($job);

            throw $exception;
        }

        return true;
    }
}
