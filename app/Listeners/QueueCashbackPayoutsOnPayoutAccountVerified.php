<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Cashback\QueueCashbackPayouts;
use App\Events\PayoutAccountVerified;
use Illuminate\Contracts\Queue\ShouldQueue;

final class QueueCashbackPayoutsOnPayoutAccountVerified implements ShouldQueue
{
    private const int MAX_ATTEMPTS = 10;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(
        private readonly QueueCashbackPayouts $queueCashbackPayouts,
    ) {}

    public function handle(PayoutAccountVerified $event): void
    {
        $this->queueCashbackPayouts->queueForUser(
            $event->payoutAccount->user_id,
        );
    }
}
