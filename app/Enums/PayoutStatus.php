<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one cashback payout.
 */
enum PayoutStatus: string
{
    case Started = 'started';
    case Ambiguous = 'ambiguous';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case InsufficientFunds = 'insufficient_funds';
    case RateLimited = 'rate_limited';
    case Rejected = 'rejected';
    case OtpRequired = 'otp_required';
    case Failed = 'failed';
    case Reversed = 'reversed';
}
