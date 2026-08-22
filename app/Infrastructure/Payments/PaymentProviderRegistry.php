<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

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

    /**
     * @param  iterable<int, TransferRecipientGateway>  $recipientGateways
     */
    public function __construct(
        #[Tag(TransferRecipientGateway::class)] iterable $recipientGateways,
        #[Config('payments.default', 'fake')] private string $defaultProvider,
    ) {
        $indexed = [];

        foreach ($recipientGateways as $gateway) {
            $provider = $gateway->provider()->value;

            if (array_key_exists($provider, $indexed)) {
                throw new LogicException('Only one transfer recipient gateway may be registered per provider.');
            }

            $indexed[$provider] = $gateway;
        }

        $this->recipientGateways = $indexed;
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
}
