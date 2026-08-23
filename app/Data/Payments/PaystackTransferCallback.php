<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\PaystackTransferEvent;

final readonly class PaystackTransferCallback
{
    public function __construct(
        public PaystackTransferEvent $event,
        public string $providerReference,
        public string $transferCode,
        public string $recipientCode,
        public int $amountMinor,
    ) {}
}
