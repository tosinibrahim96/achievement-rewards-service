<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Enums\Currency;
use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $amountMinor,
        public Currency $currency,
    ) {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('A monetary amount cannot be negative.');
        }
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }
}
