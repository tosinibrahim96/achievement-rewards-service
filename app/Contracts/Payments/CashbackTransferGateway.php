<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\CashbackTransferVerification;
use App\Data\Payments\TransferBalance;
use App\Enums\Currency;
use App\Enums\PaymentProvider;

interface CashbackTransferGateway
{
    public function provider(): PaymentProvider;

    public function availableBalance(Currency $currency): TransferBalance;

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult;

    public function verifyTransfer(string $providerReference): CashbackTransferVerification;
}
