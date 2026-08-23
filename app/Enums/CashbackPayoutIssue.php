<?php

declare(strict_types=1);

namespace App\Enums;

enum CashbackPayoutIssue: string
{
    case FundingRequired = 'funding_required';
    case StatusUncertain = 'status_uncertain';
    case TemporaryRejection = 'temporary_rejection';
    case HumanReview = 'human_review';

    public function reason(): string
    {
        return match ($this) {
            self::FundingRequired => 'The available payout balance was insufficient.',
            self::StatusUncertain => 'The provider outcome could not be confirmed.',
            self::TemporaryRejection => 'The provider temporarily rejected the transfer.',
            self::HumanReview => 'The transfer requires manual review.',
        };
    }

    public function nextAction(): string
    {
        return match ($this) {
            self::FundingRequired => 'Fund the payout balance, then review the unresolved reward before any new transfer.',
            self::StatusUncertain => 'Verify the existing transfer from its stored payment record before considering another transfer.',
            self::TemporaryRejection => 'Review the stored attempt and provider availability before deciding whether to retry manually.',
            self::HumanReview => 'Inspect the stored payout attempt and resolve the customer\'s outstanding reward.',
        };
    }
}
