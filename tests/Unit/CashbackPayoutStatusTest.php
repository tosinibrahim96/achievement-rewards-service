<?php

declare(strict_types=1);

use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutAttemptStatus;
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

it('shows the reward status for every payout attempt status', function (
    PayoutAttemptStatus $attemptStatus,
    CashbackRewardStatus $rewardStatus,
): void {
    expect(CashbackRewardStatus::forAttempt($attemptStatus))->toBe($rewardStatus);
})->with([
    'started payment is processing' => [
        PayoutAttemptStatus::Started,
        CashbackRewardStatus::Processing,
    ],
    'unknown payment result is processing' => [
        PayoutAttemptStatus::Ambiguous,
        CashbackRewardStatus::Processing,
    ],
    'pending payment is pending' => [
        PayoutAttemptStatus::Pending,
        CashbackRewardStatus::Pending,
    ],
    'successful payment is paid' => [
        PayoutAttemptStatus::Succeeded,
        CashbackRewardStatus::Paid,
    ],
    'insufficient funds waits for funds' => [
        PayoutAttemptStatus::InsufficientFunds,
        CashbackRewardStatus::AwaitingFunds,
    ],
    'temporary rejection needs attention because there is no retry worker' => [
        PayoutAttemptStatus::RetryableRejection,
        CashbackRewardStatus::RequiresAttention,
    ],
    'permanent rejection needs attention' => [
        PayoutAttemptStatus::PermanentRejection,
        CashbackRewardStatus::RequiresAttention,
    ],
    'OTP request needs attention' => [
        PayoutAttemptStatus::OtpRequired,
        CashbackRewardStatus::RequiresAttention,
    ],
    'failed payment needs attention' => [
        PayoutAttemptStatus::Failed,
        CashbackRewardStatus::RequiresAttention,
    ],
    'reversed payment needs attention' => [
        PayoutAttemptStatus::Reversed,
        CashbackRewardStatus::RequiresAttention,
    ],
]);

it('allows only callbacks that can still change the attempt', function (
    PayoutAttemptStatus $attemptStatus,
    bool $allowsSuccess,
    bool $allowsFailure,
    bool $allowsReversal,
): void {
    expect(PaystackTransferEvent::Succeeded->canChangeAttemptFrom($attemptStatus))->toBe($allowsSuccess)
        ->and(PaystackTransferEvent::Failed->canChangeAttemptFrom($attemptStatus))->toBe($allowsFailure)
        ->and(PaystackTransferEvent::Reversed->canChangeAttemptFrom($attemptStatus))->toBe($allowsReversal);
})->with([
    'started payment accepts any final callback' => [
        PayoutAttemptStatus::Started,
        true,
        true,
        true,
    ],
    'unknown payment result accepts any final callback' => [
        PayoutAttemptStatus::Ambiguous,
        true,
        true,
        true,
    ],
    'pending payment accepts any final callback' => [
        PayoutAttemptStatus::Pending,
        true,
        true,
        true,
    ],
    'successful payment accepts only reversal' => [
        PayoutAttemptStatus::Succeeded,
        false,
        false,
        true,
    ],
    'insufficient funds accepts no callback' => [
        PayoutAttemptStatus::InsufficientFunds,
        false,
        false,
        false,
    ],
    'temporary rejection accepts no callback' => [
        PayoutAttemptStatus::RetryableRejection,
        false,
        false,
        false,
    ],
    'permanent rejection accepts no callback' => [
        PayoutAttemptStatus::PermanentRejection,
        false,
        false,
        false,
    ],
    'OTP request accepts any final callback' => [
        PayoutAttemptStatus::OtpRequired,
        true,
        true,
        true,
    ],
    'failed payment accepts no callback' => [
        PayoutAttemptStatus::Failed,
        false,
        false,
        false,
    ],
    'reversed payment accepts no callback' => [
        PayoutAttemptStatus::Reversed,
        false,
        false,
        false,
    ],
]);
