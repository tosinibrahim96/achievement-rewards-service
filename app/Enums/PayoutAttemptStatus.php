<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutAttemptStatus: string
{
    case Started = 'started';
    case Ambiguous = 'ambiguous';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case InsufficientFunds = 'insufficient_funds';
    case RetryableRejection = 'retryable_rejection';
    case PermanentRejection = 'permanent_rejection';
    case OtpRequired = 'otp_required';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
