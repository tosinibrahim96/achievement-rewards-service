<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\Currency;
use App\Enums\PaymentProvider;
use InvalidArgumentException;

final readonly class CreatedTransferRecipient
{
    public function __construct(
        public PaymentProvider $provider,
        public string $recipientCode,
        public string $accountName,
        public string $bankName,
        public string $bankCode,
        public string $accountLastFour,
        public Currency $currency,
    ) {
        if ($recipientCode === '' || $accountName === '' || $bankName === '') {
            throw new InvalidArgumentException('A created transfer recipient requires canonical provider details.');
        }

        if (preg_match('/^\d{3}$/', $bankCode) !== 1 || preg_match('/^\d{4}$/', $accountLastFour) !== 1) {
            throw new InvalidArgumentException('A created transfer recipient contains invalid masked bank details.');
        }
    }
}
