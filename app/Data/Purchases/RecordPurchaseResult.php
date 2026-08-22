<?php

declare(strict_types=1);

namespace App\Data\Purchases;

use App\Models\Purchase;

final readonly class RecordPurchaseResult
{
    public function __construct(
        public Purchase $purchase,
        public bool $wasDuplicate,
    ) {}
}
