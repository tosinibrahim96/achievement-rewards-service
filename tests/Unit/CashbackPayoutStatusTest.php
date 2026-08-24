<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutStatus;
use App\Enums\PaystackTransferEvent;

it('uses only the three supported Paystack callback events', function (): void {
    expect(array_map(
        static fn (PaystackTransferEvent $event): string => $event->value,
        PaystackTransferEvent::cases(),
    ))->toBe([
        'transfer.success',
        'transfer.failed',
        'transfer.reversed',
    ]);
});

it('shows the reward status for every payout status', function (
    PayoutStatus $payoutStatus,
    CashbackRewardStatus $rewardStatus,
): void {
    expect(CashbackRewardStatus::forPayout($payoutStatus))->toBe($rewardStatus);
})->with([
    'started payout is processing' => [
        PayoutStatus::Started,
        CashbackRewardStatus::Processing,
    ],
    'unknown payout result is processing' => [
        PayoutStatus::Ambiguous,
        CashbackRewardStatus::Processing,
    ],
    'pending payout is pending' => [
        PayoutStatus::Pending,
        CashbackRewardStatus::Pending,
    ],
    'successful payout is paid' => [
        PayoutStatus::Succeeded,
        CashbackRewardStatus::Paid,
    ],
    'insufficient funds waits for funds' => [
        PayoutStatus::InsufficientFunds,
        CashbackRewardStatus::AwaitingFunds,
    ],
    'temporary rejection needs attention because there is no retry worker' => [
        PayoutStatus::RetryableRejection,
        CashbackRewardStatus::RequiresAttention,
    ],
    'permanent rejection needs attention' => [
        PayoutStatus::PermanentRejection,
        CashbackRewardStatus::RequiresAttention,
    ],
    'OTP request needs attention' => [
        PayoutStatus::OtpRequired,
        CashbackRewardStatus::RequiresAttention,
    ],
    'failed payout needs attention' => [
        PayoutStatus::Failed,
        CashbackRewardStatus::RequiresAttention,
    ],
    'reversed payout needs attention' => [
        PayoutStatus::Reversed,
        CashbackRewardStatus::RequiresAttention,
    ],
]);

it('allows only callbacks that can still change the payout', function (
    PayoutStatus $payoutStatus,
    bool $allowsSuccess,
    bool $allowsFailure,
    bool $allowsReversal,
): void {
    expect(PaystackTransferEvent::Succeeded->canChangePayoutFrom($payoutStatus))->toBe($allowsSuccess)
        ->and(PaystackTransferEvent::Failed->canChangePayoutFrom($payoutStatus))->toBe($allowsFailure)
        ->and(PaystackTransferEvent::Reversed->canChangePayoutFrom($payoutStatus))->toBe($allowsReversal);
})->with([
    'started payout accepts any final callback' => [
        PayoutStatus::Started,
        true,
        true,
        true,
    ],
    'unknown payout result accepts any final callback' => [
        PayoutStatus::Ambiguous,
        true,
        true,
        true,
    ],
    'pending payout accepts any final callback' => [
        PayoutStatus::Pending,
        true,
        true,
        true,
    ],
    'successful payout accepts only reversal' => [
        PayoutStatus::Succeeded,
        false,
        false,
        true,
    ],
    'insufficient funds accepts no callback' => [
        PayoutStatus::InsufficientFunds,
        false,
        false,
        false,
    ],
    'temporary rejection accepts no callback' => [
        PayoutStatus::RetryableRejection,
        false,
        false,
        false,
    ],
    'permanent rejection accepts no callback' => [
        PayoutStatus::PermanentRejection,
        false,
        false,
        false,
    ],
    'OTP request accepts any final callback' => [
        PayoutStatus::OtpRequired,
        true,
        true,
        true,
    ],
    'failed payout accepts no callback' => [
        PayoutStatus::Failed,
        false,
        false,
        false,
    ],
    'reversed payout accepts no callback' => [
        PayoutStatus::Reversed,
        false,
        false,
        false,
    ],
]);
