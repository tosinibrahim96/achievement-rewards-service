<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\CashbackTransferErrorCode;
use App\Enums\PayoutAttemptStatus;
use InvalidArgumentException;

final readonly class CashbackTransferResult
{
    public function __construct(
        public PayoutAttemptStatus $status,
        public ?string $transferCode = null,
        public ?int $httpStatus = null,
        public ?CashbackTransferErrorCode $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $latencyMs = null,
        public ?int $observedBalanceMinor = null,
    ) {
        if ($transferCode === '' || $errorMessage === '') {
            throw new InvalidArgumentException('Nullable transfer result text must be non-empty when present.');
        }

        if ($status === PayoutAttemptStatus::Started) {
            throw new InvalidArgumentException('A transfer result must describe an observation after initiation.');
        }

        $providerCreatedStatuses = [
            PayoutAttemptStatus::Pending,
            PayoutAttemptStatus::Succeeded,
            PayoutAttemptStatus::OtpRequired,
            PayoutAttemptStatus::Failed,
            PayoutAttemptStatus::Reversed,
        ];

        if (in_array($status, $providerCreatedStatuses, true) && $transferCode === null) {
            throw new InvalidArgumentException('A provider-created transfer result requires a transfer code.');
        }

        $preCreationStatuses = [
            PayoutAttemptStatus::InsufficientFunds,
            PayoutAttemptStatus::RetryableRejection,
            PayoutAttemptStatus::PermanentRejection,
        ];

        if (in_array($status, $preCreationStatuses, true) && $transferCode !== null) {
            throw new InvalidArgumentException('A pre-creation transfer result cannot carry a transfer code.');
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
