<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Data\Cashback\CashbackPaymentClaim;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Enums\CashbackRewardStatus;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutAttemptStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class ProcessCashbackPayment
{
    public function __construct(private PaymentProviderRegistry $paymentProviders) {}

    public function handle(int $cashbackRewardId): ?PayoutAttempt
    {
        if (DB::connection()->transactionLevel() > 0) {
            throw new LogicException('Cashback payment processing cannot run inside an existing database transaction.');
        }

        $claim = $this->claim($cashbackRewardId);

        if ($claim === null) {
            return null;
        }

        try {
            $gateway = $this->paymentProviders->transferGatewayFor($claim->provider);
        } catch (PaymentProviderException $exception) {
            if ($exception->failure !== PaymentProviderFailure::Unavailable) {
                throw $exception;
            }

            $result = new CashbackTransferResult(
                status: PayoutAttemptStatus::PermanentRejection,
                errorCode: CashbackTransferErrorCode::ProviderUnavailable,
                errorMessage: 'The persisted payment provider is unavailable.',
            );

            return $this->complete($claim, $result);
        }

        $result = $gateway->initiateTransfer($claim->request);

        return $this->complete($claim, $result);
    }

    private function claim(int $cashbackRewardId): ?CashbackPaymentClaim
    {
        return DB::transaction(function () use ($cashbackRewardId): ?CashbackPaymentClaim {
            $reward = CashbackReward::query()
                ->whereKey($cashbackRewardId)
                ->lockForUpdate()
                ->first();

            if ($reward === null
                || $reward->status !== CashbackRewardStatus::AwaitingPayoutAccount
                || $reward->provider !== null
                || $reward->payoutAttempts()->exists()) {
                return null;
            }

            $payoutAccount = PayoutAccount::query()
                ->where('user_id', $reward->user_id)
                ->lockForUpdate()
                ->first();

            if ($payoutAccount === null) {
                return null;
            }

            $startedAt = now();
            $attempt = PayoutAttempt::query()->create([
                'cashback_reward_id' => $reward->id,
                'attempt_number' => 1,
                'payout_account_id' => $payoutAccount->id,
                'provider' => $payoutAccount->provider,
                'provider_reference' => $reward->provider_reference,
                'provider_recipient_code' => $payoutAccount->provider_recipient_code,
                'amount_minor' => $reward->amount_minor,
                'currency' => $reward->currency,
                'status' => PayoutAttemptStatus::Started,
                'started_at' => $startedAt,
            ]);

            $reward->fill([
                'provider' => $payoutAccount->provider,
                'status' => CashbackRewardStatus::Processing,
                'last_attempted_at' => $startedAt,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return new CashbackPaymentClaim(
                cashbackRewardId: $reward->id,
                payoutAttemptId: $attempt->id,
                provider: $attempt->provider,
                request: new CashbackTransferRequest(
                    providerReference: $attempt->provider_reference,
                    recipientCode: $attempt->provider_recipient_code,
                    amountMinor: $attempt->amount_minor,
                    currency: $attempt->currency,
                ),
            );
        });
    }

    private function complete(
        CashbackPaymentClaim $claim,
        CashbackTransferResult $result,
    ): ?PayoutAttempt {
        return DB::transaction(function () use ($claim, $result): ?PayoutAttempt {
            $reward = CashbackReward::query()
                ->whereKey($claim->cashbackRewardId)
                ->lockForUpdate()
                ->first();

            if ($reward === null) {
                return null;
            }

            $attempt = PayoutAttempt::query()
                ->whereKey($claim->payoutAttemptId)
                ->where('cashback_reward_id', $reward->id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null
                || $reward->status !== CashbackRewardStatus::Processing
                || $attempt->status !== PayoutAttemptStatus::Started) {
                return $attempt;
            }

            $completedAt = now();
            $attempt->fill([
                'status' => $result->status,
                'provider_transfer_code' => $result->transferCode,
                'provider_http_status' => $result->httpStatus,
                'provider_error_code' => $result->errorCode?->value,
                'provider_error_message' => $result->errorMessage,
                'provider_latency_ms' => $result->latencyMs,
                'observed_balance_minor' => $result->observedBalanceMinor,
                'succeeded_at' => $result->status === PayoutAttemptStatus::Succeeded
                    ? $completedAt
                    : null,
                'reversed_at' => $result->status === PayoutAttemptStatus::Reversed
                    ? $completedAt
                    : null,
                'completed_at' => $completedAt,
            ])->save();

            $rewardValues = [
                'status' => $this->rewardStatusFor($result->status),
                'last_error_code' => $result->errorCode?->value,
                'last_error_message' => $result->errorMessage,
                'paid_at' => $result->status === PayoutAttemptStatus::Succeeded
                    ? $completedAt
                    : null,
            ];

            if ($result->observedBalanceMinor !== null) {
                $rewardValues['last_observed_balance_minor'] = $result->observedBalanceMinor;
                $rewardValues['balance_observed_at'] = $completedAt;
            }

            $reward->fill($rewardValues)->save();

            return $attempt;
        });
    }

    private function rewardStatusFor(PayoutAttemptStatus $status): CashbackRewardStatus
    {
        return match ($status) {
            PayoutAttemptStatus::Started => throw new LogicException(
                'A transfer gateway cannot complete a payout attempt as started.',
            ),
            PayoutAttemptStatus::Ambiguous => CashbackRewardStatus::Processing,
            PayoutAttemptStatus::Pending => CashbackRewardStatus::Pending,
            PayoutAttemptStatus::Succeeded => CashbackRewardStatus::Paid,
            PayoutAttemptStatus::InsufficientFunds => CashbackRewardStatus::AwaitingFunds,
            PayoutAttemptStatus::RetryableRejection => CashbackRewardStatus::Retrying,
            PayoutAttemptStatus::PermanentRejection,
            PayoutAttemptStatus::OtpRequired,
            PayoutAttemptStatus::Failed,
            PayoutAttemptStatus::Reversed => CashbackRewardStatus::RequiresAttention,
        };
    }
}
