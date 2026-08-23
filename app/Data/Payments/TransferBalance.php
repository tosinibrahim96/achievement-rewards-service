<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\Currency;
use InvalidArgumentException;

final readonly class TransferBalance
{
    public function __construct(
        public int $amountMinor,
        public Currency $currency,
    ) {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('An available transfer balance cannot be negative.');
        }
    }
}
