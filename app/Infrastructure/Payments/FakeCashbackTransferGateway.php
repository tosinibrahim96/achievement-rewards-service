<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Exceptions\Payments\PaymentProviderException;
use Illuminate\Container\Attributes\Config;

final readonly class FakeCashbackTransferGateway implements CashbackTransferGateway
{
    private const FUNDED_BALANCE_MINOR = 1_000_000_000;

    /** @var list<string> */
    private const SCENARIOS = [
        'success',
        'pending',
        'insufficient_funds',
        'permanent_failure',
    ];

    public function __construct(
        private FakeTransferEffectRegistry $effects,
        #[Config('payments.fake.transfer_scenario', 'success')] private string $scenario,
    ) {
        if (! in_array($scenario, self::SCENARIOS, true)) {
            throw PaymentProviderException::unavailable();
        }
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Fake;
    }

    public function availableBalance(Currency $currency): TransferBalance
    {
        return new TransferBalance(
            amountMinor: $this->scenario === 'insufficient_funds' ? 0 : self::FUNDED_BALANCE_MINOR,
            currency: $currency,
        );
    }

    public function initiateTransfer(CashbackTransferRequest $request): CashbackTransferResult
    {
        $existing = $this->effects->findForRequest($request);

        if ($existing !== null) {
            return $existing;
        }

        return match ($this->scenario) {
            'success' => $this->effects->create($request, PayoutStatus::Succeeded),
            'pending' => $this->effects->create($request, PayoutStatus::Pending),
            'insufficient_funds' => new CashbackTransferResult(
                status: PayoutStatus::InsufficientFunds,
                errorCode: CashbackTransferErrorCode::InsufficientFunds,
                errorMessage: 'The fake provider balance is insufficient.',
                latencyMs: 0,
                observedBalanceMinor: 0,
            ),
            'permanent_failure' => new CashbackTransferResult(
                status: PayoutStatus::Rejected,
                errorCode: CashbackTransferErrorCode::PermanentFailure,
                errorMessage: 'The fake provider rejected the transfer.',
                latencyMs: 0,
            ),
            default => throw PaymentProviderException::unavailable(),
        };
    }
}
