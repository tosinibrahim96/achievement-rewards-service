<?php

declare(strict_types=1);

namespace App\Enums;

enum PaystackTransferEvent: string
{
    case Succeeded = 'transfer.success';
    case Failed = 'transfer.failed';
    case Reversed = 'transfer.reversed';

    public function providerStatus(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'failed',
            self::Reversed => 'reversed',
        };
    }

    public function attemptStatus(): PayoutAttemptStatus
    {
        return match ($this) {
            self::Succeeded => PayoutAttemptStatus::Succeeded,
            self::Failed => PayoutAttemptStatus::Failed,
            self::Reversed => PayoutAttemptStatus::Reversed,
        };
    }
}
