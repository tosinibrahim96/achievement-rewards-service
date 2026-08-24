<?php

declare(strict_types=1);

namespace App\Enums;

enum CashbackPayoutIssue: string
{
    case FundingRequired = 'funding_required';
    case StatusUncertain = 'status_uncertain';
    case RateLimited = 'rate_limited';
    case HumanReview = 'human_review';

    public function reason(): string
    {
        return match ($this) {
            self::FundingRequired => 'The available payout balance was insufficient.',
            self::StatusUncertain => 'The provider outcome could not be confirmed.',
            self::RateLimited => 'The provider rate limited the payout request before creating a transfer.',
            self::HumanReview => 'The payout requires manual review.',
        };
    }

    public function nextAction(): string
    {
        return match ($this) {
            self::FundingRequired => 'Fund the payout balance, then inspect the stored payout and resolve the customer\'s outstanding reward.',
            self::StatusUncertain => 'Wait for a matching callback; if none arrives, inspect the existing transfer in Paystack and resolve the customer\'s outstanding reward.',
            self::RateLimited => 'Inspect the stored rate-limit result and resolve the customer\'s outstanding reward.',
            self::HumanReview => 'Inspect the stored payout and resolve the customer\'s outstanding reward.',
        };
    }
}
