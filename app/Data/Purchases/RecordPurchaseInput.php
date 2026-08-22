<?php

declare(strict_types=1);

namespace App\Data\Purchases;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;

final readonly class RecordPurchaseInput
{
    public function __construct(
        public int $userId,
        public string $externalReference,
        public Money $amount,
        public CarbonImmutable $completedAt,
    ) {}
}
