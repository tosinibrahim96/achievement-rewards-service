<?php

declare(strict_types=1);

namespace App\Data\Cashback;

use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutStatus;
use App\Enums\ProviderWebhookReceiptResult;

final readonly class RecordedPaystackWebhook
{
    public function __construct(
        public int $receiptId,
        public ?string $eventType,
        public ProviderWebhookReceiptResult $result,
        public ?int $cashbackRewardId,
        public ?int $payoutId,
        public ?PayoutStatus $oldPayoutStatus,
        public ?PayoutStatus $newPayoutStatus,
        public ?CashbackRewardStatus $rewardStatus,
        public ?string $correlationId,
        public ?CashbackPayoutSupportRequest $supportRequest,
    ) {}
}
