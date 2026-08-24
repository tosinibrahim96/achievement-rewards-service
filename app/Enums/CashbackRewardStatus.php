<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether the customer's cashback is still owed or has been paid.
 */
enum CashbackRewardStatus: string
{
    case AwaitingPayoutAccount = 'awaiting_payout_account';
    case AwaitingFunds = 'awaiting_funds';
    case Pending = 'pending';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Paid = 'paid';
    case RequiresAttention = 'requires_attention';

    public static function forAttempt(PayoutAttemptStatus $status): self
    {
        /*
         * The reward status says what the customer sees. The attempt status records
         * the provider call. Started or Ambiguous means a payment may exist, so the
         * reward stays Processing. Pending is still with the provider. Success is
         * Paid. Low funds is AwaitingFunds. Every other result needs support.
         */
        return match ($status) {
            PayoutAttemptStatus::Started,
            PayoutAttemptStatus::Ambiguous => self::Processing,
            PayoutAttemptStatus::Pending => self::Pending,
            PayoutAttemptStatus::Succeeded => self::Paid,
            PayoutAttemptStatus::InsufficientFunds => self::AwaitingFunds,
            PayoutAttemptStatus::RetryableRejection,
            PayoutAttemptStatus::PermanentRejection,
            PayoutAttemptStatus::OtpRequired,
            PayoutAttemptStatus::Failed,
            PayoutAttemptStatus::Reversed => self::RequiresAttention,
        };
    }
}
