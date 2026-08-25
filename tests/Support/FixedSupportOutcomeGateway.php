<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\Currency;
use App\Enums\PaymentProvider;

final class FixedSupportOutcomeGateway implements CashbackTransferGateway
{
    public function __construct(private readonly CashbackTransferResult $result) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function availableBalance(Currency $currency): TransferBalance
    {
        return new TransferBalance(1_000_000, $currency);
    }

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
    {
        return $this->result;
    }
}
