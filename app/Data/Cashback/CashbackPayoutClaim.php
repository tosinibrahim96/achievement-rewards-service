<?php

declare(strict_types=1);

namespace App\Data\Cashback;

use App\Data\Payments\CashbackTransferRequest;
use App\Enums\PaymentProvider;

final readonly class CashbackPayoutClaim
{
    public function __construct(
        public int $cashbackRewardId,
        public int $payoutId,
        public PaymentProvider $provider,
        public CashbackTransferRequest $request,
    ) {}
}
