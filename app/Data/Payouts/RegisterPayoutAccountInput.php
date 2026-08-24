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
        if (preg_match('/\A[0-9]{10}\z/', $accountNumber) !== 1) {
            throw new InvalidArgumentException('A payout account number must contain exactly ten digits.');
        }

        if (preg_match('/\A[0-9]{3}\z/', $bankCode) !== 1) {
            throw new InvalidArgumentException('A payout bank code must contain exactly three digits.');
        }
    }
}
