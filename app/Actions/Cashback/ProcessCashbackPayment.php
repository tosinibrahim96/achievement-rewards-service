<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Data\Cashback\CashbackPaymentClaim;
use App\Data\Cashback\CashbackPayoutSupportRequest;
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
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;
use Throwable;

final readonly class ProcessCashbackPayment
{
    public function __construct(
        private PaymentProviderRegistry $paymentProviders,
        private RequestCashbackPayoutSupport $requestPayoutSupport,
    ) {}

    public function handle(int $cashbackRewardId): ?PayoutAttempt
    {
        if (DB::connection()->transactionLevel() > 0) {
            throw new LogicException('Cashback payment processing cannot run inside an existing database transaction.');
        }

        /*
         * First save that this payment has started. Call the provider with no
         * database locks held, then save its result. A database rollback cannot
         * undo the provider call.
         */
        $claim = $this->claimPayment($cashbackRewardId);

        if ($claim === null) {
            return null;
        }

        try {
            $gateway = $this->paymentProviders->transferGatewayFor($claim->provider);
        } catch (PaymentProviderException $exception) {
            if ($exception->failure !== PaymentProviderFailure::Unavailable) {
                throw $exception;
            }

            $transferResult = new CashbackTransferResult(
                status: PayoutAttemptStatus::PermanentRejection,
                errorCode: CashbackTransferErrorCode::ProviderUnavailable,
                errorMessage: 'The persisted payment provider is unavailable.',
            );

            return $this->finishPayment($claim, $transferResult);
        }

        $transferResult = $gateway->initiateTransfer($claim->request);

        return $this->finishPayment($claim, $transferResult);
    }

    private function finishPayment(
        #[SensitiveParameter] CashbackPaymentClaim $claim,
        #[SensitiveParameter] CashbackTransferResult $transferResult,
    ): ?PayoutAttempt {
        $completion = $this->saveTransferResult($claim, $transferResult);

        if ($completion === null) {
            return null;
        }

        $attempt = $completion['attempt'];
        $reward = $completion['reward'];

        try {
            Context::scope(function () use ($claim, $transferResult, $attempt, $reward, $completion): void {
                Log::info('cashback.payout.processed', [
                    'cashback_reward_id' => $reward->id,
                    'payout_attempt_id' => $attempt->id,
                    'provider' => $claim->provider->value,
                    'state_changed' => $completion['state_changed'],
                    'attempt_status' => $attempt->status->value,
                    'reward_status' => $reward->status->value,
                    'error_code' => $transferResult->errorCode?->value,
                    'provider_http_status' => $transferResult->httpStatus,
                    'provider_latency_ms' => $transferResult->latencyMs,
                    'correlation_id' => $reward->correlation_id,
                ]);
            }, ['correlation_id' => $reward->correlation_id]);
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                /*
                 * The payout result is already saved. A logging error must not stop
                 * the support message.
                 */
            }
        }

        if ($completion['support'] !== null) {
            $this->requestPayoutSupport->dispatch($completion['support']);
        }

        return $attempt;
    }

    private function claimPayment(int $cashbackRewardId): ?CashbackPaymentClaim
    {
        return DB::transaction(function () use ($cashbackRewardId): ?CashbackPaymentClaim {
            $reward = CashbackReward::query()
                ->whereKey($cashbackRewardId)
                ->lockForUpdate()
                ->first();

            if ($reward === null
                || $reward->status !== CashbackRewardStatus::ReadyForPayout
                || $reward->provider !== null
                || $reward->payoutAttempts()->exists()) {
                return null;
            }

            $payoutAccount = PayoutAccount::query()
                ->where('user_id', $reward->user_id)
                ->whereNotNull('verified_at')
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

    /**
     * @return array{
     *     attempt: PayoutAttempt,
     *     reward: CashbackReward,
     *     state_changed: bool,
     *     support: CashbackPayoutSupportRequest|null
     * }|null
     */
    private function saveTransferResult(
        #[SensitiveParameter] CashbackPaymentClaim $claim,
        #[SensitiveParameter] CashbackTransferResult $transferResult,
    ): ?array {
        return DB::transaction(function () use ($claim, $transferResult): ?array {
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

            if ($attempt === null) {
                return null;
            }

            $supportRequest = null;

            /*
             * A webhook may update these rows while we wait for the provider. Save
             * this response only if both rows are still in the states we started with.
             */
            if ($reward->status === CashbackRewardStatus::Processing
                && $attempt->status === PayoutAttemptStatus::Started) {
                $completedAt = now();
                $attempt->fill([
                    'status' => $transferResult->status,
                    'provider_transfer_code' => $transferResult->transferCode,
                    'provider_http_status' => $transferResult->httpStatus,
                    'provider_error_code' => $transferResult->errorCode?->value,
                    'provider_error_message' => $transferResult->errorMessage,
                    'provider_latency_ms' => $transferResult->latencyMs,
                    'observed_balance_minor' => $transferResult->observedBalanceMinor,
                    'succeeded_at' => $transferResult->status === PayoutAttemptStatus::Succeeded
                        ? $completedAt
                        : null,
                    'reversed_at' => $transferResult->status === PayoutAttemptStatus::Reversed
                        ? $completedAt
                        : null,
                    'completed_at' => $completedAt,
                ]);

                $rewardValues = [
                    'status' => CashbackRewardStatus::forAttempt($transferResult->status),
                    'last_error_code' => $transferResult->errorCode?->value,
                    'last_error_message' => $transferResult->errorMessage,
                    'paid_at' => $transferResult->status === PayoutAttemptStatus::Succeeded
                        ? $completedAt
                        : null,
                ];

                if ($transferResult->observedBalanceMinor !== null) {
                    $rewardValues['last_observed_balance_minor'] = $transferResult->observedBalanceMinor;
                    $rewardValues['balance_observed_at'] = $completedAt;
                }

                $reward->fill($rewardValues);
                $supportRequest = $this->requestPayoutSupport->markWhileLocked($reward, $attempt);
                $attempt->save();
                $reward->save();
            }

            return [
                'attempt' => $attempt,
                'reward' => $reward,
                'state_changed' => $attempt->wasChanged('status'),
                'support' => $supportRequest,
            ];
        });
    }
}
