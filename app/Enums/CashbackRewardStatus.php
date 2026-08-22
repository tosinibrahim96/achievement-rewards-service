<?php

declare(strict_types=1);

namespace App\Enums;

enum CashbackRewardStatus: string
{
    case AwaitingPayoutAccount = 'awaiting_payout_account';
    case AwaitingFunds = 'awaiting_funds';
    case Pending = 'pending';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Paid = 'paid';
    case RequiresAttention = 'requires_attention';
}
