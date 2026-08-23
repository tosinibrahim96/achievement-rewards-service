<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Contracts\Payments\CashbackTransferGateway;
use App\Contracts\Payments\TransferRecipientGateway;
use App\Enums\PaymentProvider;
use App\Exceptions\Payments\PaymentProviderException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Tag;
use LogicException;

final readonly class PaymentProviderRegistry
{
    /** @var array<string, TransferRecipientGateway> */
    private array $recipientGateways;

    /** @var array<string, CashbackTransferGateway> */
    private array $transferGateways;

    /**
     * @param  iterable<int, TransferRecipientGateway>  $recipientGateways
     * @param  iterable<int, CashbackTransferGateway>  $transferGateways
     */
    public function __construct(
        #[Tag(TransferRecipientGateway::class)] iterable $recipientGateways,
        #[Tag(CashbackTransferGateway::class)] iterable $transferGateways,
        #[Config('payments.default', 'fake')] private string $defaultProvider,
    ) {
        $indexedRecipients = [];

        foreach ($recipientGateways as $gateway) {
            $provider = $gateway->provider()->value;

            if (array_key_exists($provider, $indexedRecipients)) {
                throw new LogicException('Only one transfer recipient gateway may be registered per provider.');
            }

            $indexedRecipients[$provider] = $gateway;
        }

        $indexedTransfers = [];

        foreach ($transferGateways as $gateway) {
            $provider = $gateway->provider()->value;

            if (array_key_exists($provider, $indexedTransfers)) {
                throw new LogicException('Only one cashback transfer gateway may be registered per provider.');
            }

            $indexedTransfers[$provider] = $gateway;
        }

        $this->recipientGateways = $indexedRecipients;
        $this->transferGateways = $indexedTransfers;
    }

    public function defaultRecipientGateway(): TransferRecipientGateway
    {
        $provider = PaymentProvider::tryFrom($this->defaultProvider);

        if ($provider === null) {
            throw PaymentProviderException::unavailable();
        }

        return $this->recipientGatewayFor($provider);
    }

    public function recipientGatewayFor(PaymentProvider $provider): TransferRecipientGateway
    {
        return $this->recipientGateways[$provider->value] ?? throw PaymentProviderException::unavailable();
    }

    public function transferGatewayFor(PaymentProvider $provider): CashbackTransferGateway
    {
        return $this->transferGateways[$provider->value] ?? throw PaymentProviderException::unavailable();
    }
}
