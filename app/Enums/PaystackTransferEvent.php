<?php

declare(strict_types=1);

namespace App\Enums;

enum PaystackTransferEvent: string
{
    case Succeeded = 'transfer.success';
    case Failed = 'transfer.failed';
    case Reversed = 'transfer.reversed';

    public function transferStatus(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'failed',
            self::Reversed => 'reversed',
        };
    }

    public function newAttemptStatus(): PayoutAttemptStatus
    {
        return match ($this) {
            self::Succeeded => PayoutAttemptStatus::Succeeded,
            self::Failed => PayoutAttemptStatus::Failed,
            self::Reversed => PayoutAttemptStatus::Reversed,
        };
    }

    public function canChangeAttemptFrom(PayoutAttemptStatus $status): bool
    {
        /*
         * Started, Ambiguous, Pending, and OtpRequired do not have a final result,
         * so any final callback may change them. A successful payment may only be
         * reversed. Later callbacks cannot change any other result.
         */
        return match ($status) {
            PayoutAttemptStatus::Started,
            PayoutAttemptStatus::Ambiguous,
            PayoutAttemptStatus::Pending,
            PayoutAttemptStatus::OtpRequired => true,
            PayoutAttemptStatus::Succeeded => $this === self::Reversed,
            PayoutAttemptStatus::InsufficientFunds,
            PayoutAttemptStatus::RetryableRejection,
            PayoutAttemptStatus::PermanentRejection,
            PayoutAttemptStatus::Failed,
            PayoutAttemptStatus::Reversed => false,
        };
    }
}
