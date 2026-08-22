<?php

declare(strict_types=1);

use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;

it('creates a deterministic canonical masked fake recipient', function (): void {
    $gateway = new FakeTransferRecipientGateway('success', 'test-application-key');
    $input = new RegisterPayoutAccountInput('0000001234', '057');

    $first = $gateway->createRecipient($input);
    $second = $gateway->createRecipient($input);

    expect($gateway->provider())->toBe(PaymentProvider::Fake)
        ->and($first)->toEqual($second)
        ->and($first->provider)->toBe(PaymentProvider::Fake)
        ->and($first->recipientCode)->toStartWith('RCP_FAKE_')
        ->and($first->recipientCode)->not->toContain($input->accountNumber)
        ->and($first->accountName)->toBe('Demo Customer')
        ->and($first->bankName)->toBe('Demo Bank')
        ->and($first->bankCode)->toBe('057')
        ->and($first->accountLastFour)->toBe('1234')
        ->and($first->currency)->toBe(Currency::Ngn);
});

it('uses distinct recipient identities for distinct account details', function (): void {
    $gateway = new FakeTransferRecipientGateway('success', 'test-application-key');

    $first = $gateway->createRecipient(new RegisterPayoutAccountInput('0000001234', '057'));
    $second = $gateway->createRecipient(new RegisterPayoutAccountInput('0000001235', '057'));

    expect($first->recipientCode)->not->toBe($second->recipientCode);
});

it('rejects the configured fake scenario without exposing account details', function (): void {
    $accountNumber = '0000001234';
    $gateway = new FakeTransferRecipientGateway('rejected', 'test-application-key');

    try {
        $gateway->createRecipient(new RegisterPayoutAccountInput($accountNumber, '057'));
        test()->fail('The rejected fake scenario should throw.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::RecipientRejected)
            ->and($exception->getMessage())->not->toContain($accountNumber);
    }
});

it('fails safely when the fake scenario or identity key is unavailable', function (string $scenario, ?string $key): void {
    $gateway = new FakeTransferRecipientGateway($scenario, $key);

    expect(fn () => $gateway->createRecipient(new RegisterPayoutAccountInput('0000001234', '057')))
        ->toThrow(
            PaymentProviderException::class,
            PaymentProviderFailure::Unavailable->value,
        );
})->with([
    'unknown scenario' => ['unknown', 'test-application-key'],
    'missing key' => ['success', null],
    'empty key' => ['success', ''],
]);
