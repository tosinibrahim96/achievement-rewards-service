<?php

declare(strict_types=1);

use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\CashbackTransferVerification;
use App\Data\Payments\TransferBalance;
use App\Enums\Currency;
use App\Enums\PayoutAttemptStatus;
use InvalidArgumentException;

it('carries provider-neutral transfer facts with typed values', function (): void {
    $balance = new TransferBalance(750_000, Currency::Ngn);
    $request = new CashbackTransferRequest(
        providerReference: 'cashback-01example',
        recipientCode: 'RCP_FAKE_example',
        amountMinor: 30_000,
        currency: Currency::Ngn,
    );
    $result = new CashbackTransferResult(
        status: PayoutAttemptStatus::Pending,
        transferCode: 'TRF_example',
        httpStatus: 200,
        errorCode: null,
        errorMessage: null,
        latencyMs: 14,
        observedBalanceMinor: 750_000,
    );
    $verification = new CashbackTransferVerification($result);

    expect($balance->amountMinor)->toBe(750_000)
        ->and($balance->currency)->toBe(Currency::Ngn)
        ->and($request->providerReference)->toBe('cashback-01example')
        ->and($request->recipientCode)->toBe('RCP_FAKE_example')
        ->and($request->amountMinor)->toBe(30_000)
        ->and($request->currency)->toBe(Currency::Ngn)
        ->and($verification->result)->toBe($result)
        ->and((new CashbackTransferVerification(null))->result)->toBeNull();
});

it('rejects negative available balances', function (): void {
    expect(fn () => new TransferBalance(-1, Currency::Ngn))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects incomplete or non-positive transfer requests', function (array $override): void {
    $valid = [
        'providerReference' => 'cashback-01example',
        'recipientCode' => 'RCP_FAKE_example',
        'amountMinor' => 30_000,
        'currency' => Currency::Ngn,
    ];

    expect(fn () => new CashbackTransferRequest(...[...$valid, ...$override]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty provider reference' => [['providerReference' => '']],
    'empty recipient code' => [['recipientCode' => '']],
    'zero amount' => [['amountMinor' => 0]],
    'negative amount' => [['amountMinor' => -1]],
]);

it('rejects malformed optional transfer observations', function (array $override): void {
    $valid = [
        'status' => PayoutAttemptStatus::InsufficientFunds,
        'transferCode' => null,
        'httpStatus' => 422,
        'errorCode' => 'insufficient_funds',
        'errorMessage' => 'The available balance is insufficient.',
        'latencyMs' => 12,
        'observedBalanceMinor' => 0,
    ];

    expect(fn () => new CashbackTransferResult(...[...$valid, ...$override]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty transfer code' => [['transferCode' => '']],
    'empty error code' => [['errorCode' => '']],
    'empty error message' => [['errorMessage' => '']],
    'HTTP status below range' => [['httpStatus' => 99]],
    'HTTP status above range' => [['httpStatus' => 600]],
    'negative latency' => [['latencyMs' => -1]],
    'negative observed balance' => [['observedBalanceMinor' => -1]],
]);

it('rejects transfer results whose factual status contradicts provider effect identity', function (
    PayoutAttemptStatus $status,
    ?string $transferCode,
): void {
    expect(fn () => new CashbackTransferResult(
        status: $status,
        transferCode: $transferCode,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'started is intent rather than a result' => [PayoutAttemptStatus::Started, null],
    'pending requires a provider transfer identity' => [PayoutAttemptStatus::Pending, null],
    'success requires a provider transfer identity' => [PayoutAttemptStatus::Succeeded, null],
    'OTP requires a provider transfer identity' => [PayoutAttemptStatus::OtpRequired, null],
    'provider-created failure requires a transfer identity' => [PayoutAttemptStatus::Failed, null],
    'reversal requires a provider transfer identity' => [PayoutAttemptStatus::Reversed, null],
    'insufficient funds is pre-creation' => [PayoutAttemptStatus::InsufficientFunds, 'TRF_impossible'],
    'retryable rejection is pre-creation' => [PayoutAttemptStatus::RetryableRejection, 'TRF_impossible'],
    'permanent rejection is pre-creation' => [PayoutAttemptStatus::PermanentRejection, 'TRF_impossible'],
]);

it('freezes the factual payout attempt vocabulary', function (): void {
    expect(array_map(
        static fn (PayoutAttemptStatus $status): string => $status->value,
        PayoutAttemptStatus::cases(),
    ))->toBe([
        'started',
        'ambiguous',
        'pending',
        'succeeded',
        'insufficient_funds',
        'retryable_rejection',
        'permanent_rejection',
        'otp_required',
        'failed',
        'reversed',
    ]);
});
