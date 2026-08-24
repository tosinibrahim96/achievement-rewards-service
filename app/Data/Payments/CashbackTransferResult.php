<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\CashbackTransferErrorCode;
use App\Enums\PayoutStatus;
use InvalidArgumentException;

final readonly class CashbackTransferResult
{
    public function __construct(
        public PayoutStatus $status,
        public ?string $transferCode = null,
        public ?int $httpStatus = null,
        public ?CashbackTransferErrorCode $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $latencyMs = null,
        public ?int $observedBalanceMinor = null,
    ) {
        if ($transferCode === '') {
            throw new InvalidArgumentException('Transfer code cannot be empty.');
        }

        if ($errorMessage === '') {
            throw new InvalidArgumentException('Transfer error message cannot be empty.');
        }

        if ($status === PayoutStatus::Started) {
            throw new InvalidArgumentException(
                'The "started" payout status is saved before calling the payment provider, so it cannot be used in a transfer result.',
            );
        }

        $statusesThatRequireTransferCode = [
            PayoutStatus::Pending,
            PayoutStatus::Succeeded,
            PayoutStatus::OtpRequired,
            PayoutStatus::Failed,
            PayoutStatus::Reversed,
        ];

        if (in_array($status, $statusesThatRequireTransferCode, true) && $transferCode === null) {
            throw new InvalidArgumentException(
                sprintf('Payout status "%s" requires a transfer code.', $status->value),
            );
        }

        $statusesThatCannotHaveTransferCode = [
            PayoutStatus::InsufficientFunds,
            PayoutStatus::RetryableRejection,
            PayoutStatus::PermanentRejection,
        ];

        if (in_array($status, $statusesThatCannotHaveTransferCode, true) && $transferCode !== null) {
            throw new InvalidArgumentException(
                sprintf('Payout status "%s" cannot have a transfer code.', $status->value),
            );
        }

        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('A provider HTTP status must be between 100 and 599.');
        }

        if ($latencyMs !== null && $latencyMs < 0) {
            throw new InvalidArgumentException('Provider latency cannot be negative.');
        }

        if ($observedBalanceMinor !== null && $observedBalanceMinor < 0) {
            throw new InvalidArgumentException('An observed provider balance cannot be negative.');
        }
    }
}
