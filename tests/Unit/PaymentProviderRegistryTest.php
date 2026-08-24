<?php

declare(strict_types=1);

use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use LogicException;

function transferGatewayForRegistryTest(PaymentProvider $provider): CashbackTransferGateway
{
    return new class($provider) implements CashbackTransferGateway
    {
        public function __construct(private readonly PaymentProvider $paymentProvider) {}

        public function provider(): PaymentProvider
        {
            return $this->paymentProvider;
        }

        public function availableBalance(Currency $currency): TransferBalance
        {
            return new TransferBalance(1_000_000, $currency);
        }

        public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
        {
            return new CashbackTransferResult(
                status: PayoutStatus::Succeeded,
                transferCode: 'TRF_registry',
            );
        }
    };
}

it('selects registered recipient and transfer gateways without fallback', function (): void {
    $recipientGateway = new FakeTransferRecipientGateway('success', 'test-application-key');
    $transferGateway = transferGatewayForRegistryTest(PaymentProvider::Fake);
    $registry = new PaymentProviderRegistry(
        recipientGateways: [$recipientGateway],
        transferGateways: [$transferGateway],
        defaultProvider: PaymentProvider::Fake->value,
    );

    expect($registry->defaultRecipientGateway())->toBe($recipientGateway)
        ->and($registry->recipientGatewayFor(PaymentProvider::Fake))->toBe($recipientGateway)
        ->and($registry->transferGatewayFor(PaymentProvider::Fake))->toBe($transferGateway);
});

it('fails safely for an invalid default or an unregistered known provider', function (string $default): void {
    $registry = new PaymentProviderRegistry(
        recipientGateways: [new FakeTransferRecipientGateway('success', 'test-application-key')],
        transferGateways: [transferGatewayForRegistryTest(PaymentProvider::Fake)],
        defaultProvider: $default,
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
    expect(fn () => new PaymentProviderRegistry(
        recipientGateways: [
            new FakeTransferRecipientGateway('success', 'first-key'),
            new FakeTransferRecipientGateway('success', 'second-key'),
        ],
        transferGateways: [],
        defaultProvider: PaymentProvider::Fake->value,
    ))->toThrow(
        LogicException::class,
        'Only one transfer recipient gateway may be registered per provider.',
    );
});

it('rejects duplicate transfer gateways for one provider', function (): void {
    expect(fn () => new PaymentProviderRegistry(
        recipientGateways: [],
        transferGateways: [
            transferGatewayForRegistryTest(PaymentProvider::Fake),
            transferGatewayForRegistryTest(PaymentProvider::Fake),
        ],
        defaultProvider: PaymentProvider::Fake->value,
    ))->toThrow(
        LogicException::class,
        'Only one cashback transfer gateway may be registered per provider.',
    );
});

it('fails safely when the persisted transfer provider has no registered gateway', function (): void {
    $registry = new PaymentProviderRegistry(
        recipientGateways: [],
        transferGateways: [transferGatewayForRegistryTest(PaymentProvider::Fake)],
        defaultProvider: PaymentProvider::Fake->value,
    );

    try {
        $registry->transferGatewayFor(PaymentProvider::Paystack);
        test()->fail('An unavailable persisted transfer provider should throw.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable);
    }
});
