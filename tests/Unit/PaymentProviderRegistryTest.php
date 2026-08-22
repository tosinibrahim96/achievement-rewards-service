<?php

declare(strict_types=1);

use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use LogicException;

it('selects the configured recipient gateway without fallback', function (): void {
    $gateway = new FakeTransferRecipientGateway('success', 'test-application-key');
    $registry = new PaymentProviderRegistry([$gateway], PaymentProvider::Fake->value);

    expect($registry->defaultRecipientGateway())->toBe($gateway)
        ->and($registry->recipientGatewayFor(PaymentProvider::Fake))->toBe($gateway);
});

it('fails safely for an invalid default or an unregistered known provider', function (string $default): void {
    $registry = new PaymentProviderRegistry(
        [new FakeTransferRecipientGateway('success', 'test-application-key')],
        $default,
    );

    try {
        $registry->defaultRecipientGateway();
        test()->fail('An unavailable configured provider should throw.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable);
    }
})->with([
    'unregistered paystack adapter' => PaymentProvider::Paystack->value,
    'unknown provider' => 'unknown',
]);

it('rejects duplicate recipient gateways for one provider', function (): void {
    expect(fn () => new PaymentProviderRegistry([
        new FakeTransferRecipientGateway('success', 'first-key'),
        new FakeTransferRecipientGateway('success', 'second-key'),
    ], PaymentProvider::Fake->value))->toThrow(
        LogicException::class,
        'Only one transfer recipient gateway may be registered per provider.',
    );
});
