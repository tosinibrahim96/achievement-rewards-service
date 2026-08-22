<?php

declare(strict_types=1);

use App\Data\Payments\CreatedTransferRecipient;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use InvalidArgumentException;

it('rejects malformed bank details at the reusable payout input boundary', function (
    string $accountNumber,
    string $bankCode,
): void {
    expect(fn () => new RegisterPayoutAccountInput($accountNumber, $bankCode))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'short account number' => ['00001234', '057'],
    'non-digit account number' => ['00000012x4', '057'],
    'short bank code' => ['0000001234', '57'],
    'non-digit bank code' => ['0000001234', '05x'],
]);

it('rejects incomplete or malformed canonical recipient results', function (array $override): void {
    $valid = [
        'provider' => PaymentProvider::Fake,
        'recipientCode' => 'RCP_FAKE_test',
        'accountName' => 'Demo Customer',
        'bankName' => 'Demo Bank',
        'bankCode' => '057',
        'accountLastFour' => '1234',
        'currency' => Currency::Ngn,
    ];

    expect(fn () => new CreatedTransferRecipient(...[...$valid, ...$override]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty recipient code' => [['recipientCode' => '']],
    'empty account name' => [['accountName' => '']],
    'empty bank name' => [['bankName' => '']],
    'malformed bank code' => [['bankCode' => '57']],
    'malformed last four' => [['accountLastFour' => '12x4']],
]);
