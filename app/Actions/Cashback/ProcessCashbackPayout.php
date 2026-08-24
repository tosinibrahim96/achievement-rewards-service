<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Data\Cashback\CashbackPayoutClaim;
use App\Data\Cashback\CashbackPayoutSupportRequest;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Enums\CashbackRewardStatus;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\PaymentProviderFailure;
use App\Enums\PayoutStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;
use Throwable;

final readonly class ProcessCashbackPayout
{
    public function __construct(
        private PaymentProviderRegistry $paymentProviders,
        private RequestCashbackPayoutSupport $requestPayoutSupport,
    ) {}

    public function handle(int $cashbackRewardId): ?Payout
    {
        if (DB::connection()->transactionLevel() > 0) {
            throw new LogicException('Cashback payout processing cannot run inside an existing database transaction.');
        }

        /*
         * First save that this payout has started. Call the provider with no
         * database locks held, then save its result. A database rollback cannot
         * undo the provider call.
         */
        $claim = $this->claimPayout($cashbackRewardId);

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
                status: PayoutStatus::PermanentRejection,
                errorCode: CashbackTransferErrorCode::ProviderUnavailable,
                errorMessage: 'The persisted payment provider is unavailable.',
            );

            return $this->finishPayout($claim, $transferResult);
        }

        $transferResult = $gateway->initiateTransfer($claim->request);

        return $this->finishPayout($claim, $transferResult);
    }

    private function finishPayout(
        #[SensitiveParameter] CashbackPayoutClaim $claim,
        #[SensitiveParameter] CashbackTransferResult $transferResult,
    ): ?Payout {
        $completion = $this->saveTransferResult($claim, $transferResult);

        if ($completion === null) {
            return null;
        }

        $payout = $completion['payout'];
        $reward = $completion['reward'];

        try {
            Context::scope(function () use ($claim, $transferResult, $payout, $reward, $completion): void {
                Log::info('cashback.payout.processed', [
                    'cashback_reward_id' => $reward->id,
                    'payout_id' => $payout->id,
                    'provider' => $claim->provider->value,
                    'state_changed' => $completion['state_changed'],
                    'payout_status' => $payout->status->value,
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

        return $payout;
    }

    private function claimPayout(int $cashbackRewardId): ?CashbackPayoutClaim
    {
        return DB::transaction(function () use ($cashbackRewardId): ?CashbackPayoutClaim {
            $reward = CashbackReward::query()
                ->whereKey($cashbackRewardId)
                ->lockForUpdate()
                ->first();

            if ($reward === null
                || $reward->status !== CashbackRewardStatus::ReadyForPayout
                || $reward->provider !== null
                || $reward->payout()->exists()) {
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
            $payout = Payout::query()->create([
                'cashback_reward_id' => $reward->id,
                'payout_account_id' => $payoutAccount->id,
                'provider' => $payoutAccount->provider,
                'provider_reference' => $reward->provider_reference,
                'provider_recipient_code' => $payoutAccount->provider_recipient_code,
                'amount_minor' => $reward->amount_minor,
                'currency' => $reward->currency,
                'status' => PayoutStatus::Started,
                'started_at' => $startedAt,
            ]);

            $reward->fill([
                'provider' => $payoutAccount->provider,
                'status' => CashbackRewardStatus::Processing,
                'last_attempted_at' => $startedAt,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return new CashbackPayoutClaim(
                cashbackRewardId: $reward->id,
                payoutId: $payout->id,
                provider: $payout->provider,
                request: new CashbackTransferRequest(
                    providerReference: $payout->provider_reference,
                    recipientCode: $payout->provider_recipient_code,
                    amountMinor: $payout->amount_minor,
                    currency: $payout->currency,
                ),
            );
        });
    }

    /**
     * @return array{
     *     payout: Payout,
     *     reward: CashbackReward,
     *     state_changed: bool,
     *     support: CashbackPayoutSupportRequest|null
     * }|null
     */
    private function saveTransferResult(
        #[SensitiveParameter] CashbackPayoutClaim $claim,
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

            $payout = Payout::query()
                ->whereKey($claim->payoutId)
                ->where('cashback_reward_id', $reward->id)
                ->lockForUpdate()
                ->first();

            if ($payout === null) {
                return null;
            }

            $supportRequest = null;

            /*
             * A webhook may update these rows while we wait for the provider. Save
             * this response only if both rows are still in the states we started with.
             */
            if ($reward->status === CashbackRewardStatus::Processing
                && $payout->status === PayoutStatus::Started) {
                $completedAt = now();
                $payout->fill([
                    'status' => $transferResult->status,
                    'provider_transfer_code' => $transferResult->transferCode,
                    'provider_http_status' => $transferResult->httpStatus,
                    'provider_error_code' => $transferResult->errorCode?->value,
                    'provider_error_message' => $transferResult->errorMessage,
                    'provider_latency_ms' => $transferResult->latencyMs,
                    'observed_balance_minor' => $transferResult->observedBalanceMinor,
                    'succeeded_at' => $transferResult->status === PayoutStatus::Succeeded
                        ? $completedAt
                        : null,
                    'reversed_at' => $transferResult->status === PayoutStatus::Reversed
                        ? $completedAt
                        : null,
                    'completed_at' => $completedAt,
                ]);

                $rewardValues = [
                    'status' => CashbackRewardStatus::forPayout($transferResult->status),
                    'last_error_code' => $transferResult->errorCode?->value,
                    'last_error_message' => $transferResult->errorMessage,
                    'paid_at' => $transferResult->status === PayoutStatus::Succeeded
                        ? $completedAt
                        : null,
                ];

                if ($transferResult->observedBalanceMinor !== null) {
                    $rewardValues['last_observed_balance_minor'] = $transferResult->observedBalanceMinor;
                    $rewardValues['balance_observed_at'] = $completedAt;
                }

                $reward->fill($rewardValues);
                $supportRequest = $this->requestPayoutSupport->markWhileLocked($reward, $payout);
                $payout->save();
                $reward->save();
            }

            return [
                'payout' => $payout,
                'reward' => $reward,
                'state_changed' => $payout->wasChanged('status'),
                'support' => $supportRequest,
            ];
        });
    }
}
