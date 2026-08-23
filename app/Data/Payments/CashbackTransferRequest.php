<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\Currency;
use InvalidArgumentException;

final readonly class CashbackTransferRequest
{
    public function __construct(
        public string $providerReference,
        public string $recipientCode,
        public int $amountMinor,
        public Currency $currency,
    ) {
        if ($recipientCode === '' || $providerReference === '') {
            throw new InvalidArgumentException('A cashback transfer requires a recipient and provider reference.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('A cashback transfer amount must be positive.');
        }
    }
}
