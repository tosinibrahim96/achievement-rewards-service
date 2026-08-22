<?php

declare(strict_types=1);

namespace App\Data\Payouts;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class RegisterPayoutAccountInput
{
    public function __construct(
        #[SensitiveParameter]
        public string $accountNumber,
        public string $bankCode,
    ) {
        if (preg_match('/^\d{10}$/', $accountNumber) !== 1) {
            throw new InvalidArgumentException('A payout account number must contain exactly ten digits.');
        }

        if (preg_match('/^\d{3}$/', $bankCode) !== 1) {
            throw new InvalidArgumentException('A payout bank code must contain exactly three digits.');
        }
    }
}
