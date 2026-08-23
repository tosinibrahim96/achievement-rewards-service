<?php

declare(strict_types=1);

namespace App\Data\Cashback;

use App\Data\Payments\CashbackTransferRequest;
use App\Enums\PaymentProvider;

final readonly class CashbackPaymentClaim
{
    public function __construct(
        public int $cashbackRewardId,
        public int $payoutAttemptId,
        public PaymentProvider $provider,
        public CashbackTransferRequest $request,
    ) {}
}
