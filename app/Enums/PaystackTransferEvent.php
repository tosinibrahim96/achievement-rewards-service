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

    public function payoutStatus(): PayoutStatus
    {
        return match ($this) {
            self::Succeeded => PayoutStatus::Succeeded,
            self::Failed => PayoutStatus::Failed,
            self::Reversed => PayoutStatus::Reversed,
        };
    }

    public function canChangePayoutFrom(PayoutStatus $status): bool
    {
        /*
         * Started, Ambiguous, Pending, and OtpRequired do not have a final result,
         * so any final callback may change them. A successful transfer may only be
         * reversed. Later callbacks cannot change any other result.
         */
        return match ($status) {
            PayoutStatus::Started,
            PayoutStatus::Ambiguous,
            PayoutStatus::Pending,
            PayoutStatus::OtpRequired => true,
            PayoutStatus::Succeeded => $this === self::Reversed,
            PayoutStatus::InsufficientFunds,
            PayoutStatus::RateLimited,
            PayoutStatus::Rejected,
            PayoutStatus::Failed,
            PayoutStatus::Reversed => false,
        };
    }
}
