<?php

declare(strict_types=1);

use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PayoutStatus;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

it('carries provider-neutral transfer facts with typed values', function (): void {
    $balance = new TransferBalance(750_000, Currency::Ngn);
    $request = new CashbackTransferRequest(
        providerReference: 'cashback-01example',
        recipientCode: 'RCP_FAKE_example',
        amountMinor: 30_000,
        currency: Currency::Ngn,
    );
    $result = new CashbackTransferResult(
        status: PayoutStatus::Pending,
        transferCode: 'TRF_example',
        httpStatus: HttpResponse::HTTP_OK,
        errorCode: null,
        errorMessage: null,
        latencyMs: 14,
        observedBalanceMinor: 750_000,
    );

    expect($balance->amountMinor)->toBe(750_000)
        ->and($balance->currency)->toBe(Currency::Ngn)
        ->and($request->providerReference)->toBe('cashback-01example')
        ->and($request->recipientCode)->toBe('RCP_FAKE_example')
        ->and($request->amountMinor)->toBe(30_000)
        ->and($request->currency)->toBe(Currency::Ngn)
        ->and($result->status)->toBe(PayoutStatus::Pending)
        ->and($result->transferCode)->toBe('TRF_example')
        ->and($result->httpStatus)->toBe(HttpResponse::HTTP_OK)
        ->and($result->errorCode)->toBeNull()
        ->and($result->errorMessage)->toBeNull()
        ->and($result->latencyMs)->toBe(14)
        ->and($result->observedBalanceMinor)->toBe(750_000);
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

it('rejects malformed optional transfer details', function (array $override, string $message): void {
    $valid = [
        'status' => PayoutStatus::InsufficientFunds,
        'transferCode' => null,
        'httpStatus' => HttpResponse::HTTP_UNPROCESSABLE_ENTITY,
        'errorCode' => CashbackTransferErrorCode::InsufficientFunds,
        'errorMessage' => 'The available balance is insufficient.',
        'latencyMs' => 12,
        'observedBalanceMinor' => 0,
    ];

    expect(fn () => new CashbackTransferResult(...[...$valid, ...$override]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty transfer code' => [['transferCode' => ''], 'Transfer code cannot be empty.'],
    'empty error message' => [['errorMessage' => ''], 'Transfer error message cannot be empty.'],
    'HTTP status below range' => [['httpStatus' => 99], 'A provider HTTP status must be between 100 and 599.'],
    'HTTP status above range' => [['httpStatus' => 600], 'A provider HTTP status must be between 100 and 599.'],
    'negative latency' => [['latencyMs' => -1], 'Provider latency cannot be negative.'],
    'negative observed balance' => [['observedBalanceMinor' => -1], 'An observed provider balance cannot be negative.'],
]);

it('accepts the inclusive HTTP protocol status bounds in transfer results', function (int $httpStatus): void {
    $result = new CashbackTransferResult(
        status: PayoutStatus::Ambiguous,
        httpStatus: $httpStatus,
    );

    expect($result->httpStatus)->toBe($httpStatus);
})->with([
    'lower bound' => HttpResponse::HTTP_CONTINUE,
    'upper bound' => 599,
]);

it('rejects the started payout status because it is saved before the provider call', function (): void {
    expect(fn () => new CashbackTransferResult(status: PayoutStatus::Started))
        ->toThrow(
            InvalidArgumentException::class,
            'The "started" payout status is saved before calling the payment provider, so it cannot be used in a transfer result.',
        );
});

it('rejects payout statuses with a missing or unexpected transfer code', function (
    PayoutStatus $status,
    ?string $transferCode,
    string $message,
): void {
    expect(fn () => new CashbackTransferResult(
        status: $status,
        transferCode: $transferCode,
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'pending without transfer code' => [PayoutStatus::Pending, null, 'Payout status "pending" requires a transfer code.'],
    'succeeded without transfer code' => [PayoutStatus::Succeeded, null, 'Payout status "succeeded" requires a transfer code.'],
    'OTP required without transfer code' => [PayoutStatus::OtpRequired, null, 'Payout status "otp_required" requires a transfer code.'],
    'failed without transfer code' => [PayoutStatus::Failed, null, 'Payout status "failed" requires a transfer code.'],
    'reversed without transfer code' => [PayoutStatus::Reversed, null, 'Payout status "reversed" requires a transfer code.'],
    'insufficient funds with transfer code' => [PayoutStatus::InsufficientFunds, 'TRF_impossible', 'Payout status "insufficient_funds" cannot have a transfer code.'],
    'rate limited with transfer code' => [PayoutStatus::RateLimited, 'TRF_impossible', 'Payout status "rate_limited" cannot have a transfer code.'],
    'rejected with transfer code' => [PayoutStatus::Rejected, 'TRF_impossible', 'Payout status "rejected" cannot have a transfer code.'],
]);

it('requires the rate limited payout status and error code together', function (): void {
    $result = new CashbackTransferResult(
        status: PayoutStatus::RateLimited,
        errorCode: CashbackTransferErrorCode::RateLimited,
    );

    expect($result->status)->toBe(PayoutStatus::RateLimited)
        ->and($result->errorCode)->toBe(CashbackTransferErrorCode::RateLimited);
});

it('rejects either direction of a rate limited status and error mismatch', function (
    PayoutStatus $status,
    ?CashbackTransferErrorCode $errorCode,
): void {
    expect(fn () => new CashbackTransferResult(
        status: $status,
        errorCode: $errorCode,
    ))->toThrow(
        InvalidArgumentException::class,
        'The "rate_limited" payout status requires the matching error code, and that error code cannot be used with another status.',
    );
})->with([
    'rate limited status without an error code' => [PayoutStatus::RateLimited, null],
    'rate limited status with another error code' => [
        PayoutStatus::RateLimited,
        CashbackTransferErrorCode::ProviderRejected,
    ],
    'rate limited error on another status' => [
        PayoutStatus::Rejected,
        CashbackTransferErrorCode::RateLimited,
    ],
]);

it('freezes the factual payout status vocabulary', function (): void {
    expect(array_map(
        static fn (PayoutStatus $status): string => $status->value,
        PayoutStatus::cases(),
    ))->toBe([
        'started',
        'ambiguous',
        'pending',
        'succeeded',
        'insufficient_funds',
        'rate_limited',
        'rejected',
        'otp_required',
        'failed',
        'reversed',
    ]);
});

it('freezes the normalized cashback transfer diagnostic vocabulary', function (): void {
    expect(array_map(
        static fn (CashbackTransferErrorCode $code): string => $code->value,
        CashbackTransferErrorCode::cases(),
    ))->toBe([
        'invalid_provider_reference',
        'provider_unavailable',
        'provider_invalid_response',
        'provider_transfer_identity_missing',
        'provider_status_unknown',
        'otp_required',
        'transfer_failed',
        'transfer_reversed',
        'insufficient_funds',
        'rate_limited',
        'duplicate_reference',
        'provider_rejected',
        'provider_timeout',
        'permanent_failure',
    ]);
});
