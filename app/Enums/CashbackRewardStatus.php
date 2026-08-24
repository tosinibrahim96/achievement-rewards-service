<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether the customer's cashback is still owed or has been paid.
 */
enum CashbackRewardStatus: string
{
    case AwaitingPayoutAccount = 'awaiting_payout_account';
    case ReadyForPayout = 'ready_for_payout';
    case AwaitingFunds = 'awaiting_funds';
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case RequiresAttention = 'requires_attention';

    public static function forPayout(PayoutStatus $status): self
    {
        /*
         * The reward status says what the customer sees. The payout status records
         * the provider call. Started or Ambiguous means a transfer may exist, so the
         * reward stays Processing. Pending is still with the provider. Success is
         * Paid. Low funds is AwaitingFunds. Every other result needs support.
         */
        return match ($status) {
            PayoutStatus::Started,
            PayoutStatus::Ambiguous => self::Processing,
            PayoutStatus::Pending => self::Pending,
            PayoutStatus::Succeeded => self::Paid,
            PayoutStatus::InsufficientFunds => self::AwaitingFunds,
            PayoutStatus::RateLimited,
            PayoutStatus::Rejected,
            PayoutStatus::OtpRequired,
            PayoutStatus::Failed,
            PayoutStatus::Reversed => self::RequiresAttention,
        };
    }
}
