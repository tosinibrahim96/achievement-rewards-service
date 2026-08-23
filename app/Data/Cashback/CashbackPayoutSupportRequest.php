<?php

declare(strict_types=1);

namespace App\Data\Cashback;

use App\Enums\CashbackPayoutIssue;
use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutAttemptStatus;

final readonly class CashbackPayoutSupportRequest
{
    public function __construct(
        public int $cashbackRewardId,
        public int $payoutAttemptId,
        public CashbackPayoutIssue $issue,
        public PayoutAttemptStatus $attemptStatus,
        public CashbackRewardStatus $rewardStatus,
        public ?string $errorCode,
        public ?int $providerHttpStatus,
        public string $correlationId,
    ) {}
}
