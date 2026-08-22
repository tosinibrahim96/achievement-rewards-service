<?php

declare(strict_types=1);

namespace App\Data\Payouts;

use App\Models\PayoutAccount;

final readonly class PayoutAccountRegistrationResult
{
    public function __construct(
        public PayoutAccount $payoutAccount,
        public bool $wasCreated,
    ) {}
}
