<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use Closure;
use RuntimeException;

final class InspectingCashbackTransferGateway implements CashbackTransferGateway
{
    public int $balanceReads = 0;

    public int $initiationCalls = 0;

    /**
     * @param  Closure(CashbackTransferRequest): void  $inspect
     */
    public function __construct(
        private readonly Closure $inspect,
        private readonly CashbackTransferResult|RuntimeException $outcome,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function availableBalance(Currency $currency): TransferBalance
    {
        $this->balanceReads++;

        return new TransferBalance(1_000_000_000, $currency);
    }

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
    {
        $this->initiationCalls++;
        ($this->inspect)($request);

        if ($this->outcome instanceof RuntimeException) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
