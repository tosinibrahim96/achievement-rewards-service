<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use Closure;

final class CallbackWinningPaystackGateway implements CashbackTransferGateway
{
    /** @param Closure(CashbackTransferRequest): void $callback */
    public function __construct(private readonly Closure $callback) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    public function availableBalance(Currency $currency): TransferBalance
    {
        return new TransferBalance(1_000_000, $currency);
    }

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
    {
        ($this->callback)($request);

        return new CashbackTransferResult(
            status: PayoutStatus::Pending,
            transferCode: 'TRF_STALE_RESPONSE',
            httpStatus: 200,
            latencyMs: 9,
        );
    }
}
