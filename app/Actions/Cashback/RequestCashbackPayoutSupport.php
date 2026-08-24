<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Data\Cashback\CashbackPayoutSupportRequest;
use App\Enums\CashbackPayoutIssue;
use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutStatus;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Notifications\CashbackPayoutRequiresAttention;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;
use SensitiveParameter;
use Throwable;

final readonly class RequestCashbackPayoutSupport
{
    public function __construct(
        #[Config('rewards.support_email', 'support@example.test')]
        private string $supportEmail,
    ) {}

    public function markWhileLocked(
        #[SensitiveParameter] CashbackReward $reward,
        #[SensitiveParameter] Payout $payout,
    ): ?CashbackPayoutSupportRequest {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('A support request must be marked inside the payout transaction.');
        }

        $issue = $this->issueFor($payout->status);

        if ($payout->cashback_reward_id !== $reward->id
            || $payout->support_alert_requested_at !== null
            || $issue === null
            || ! in_array($reward->status, [
                CashbackRewardStatus::AwaitingFunds,
                CashbackRewardStatus::Processing,
                CashbackRewardStatus::RequiresAttention,
            ], true)) {
            return null;
        }

        $payout->support_alert_requested_at = now()->toImmutable();

        return new CashbackPayoutSupportRequest(
            cashbackRewardId: $reward->id,
            payoutId: $payout->id,
            issue: $issue,
            payoutStatus: $payout->status,
            rewardStatus: $reward->status,
            errorCode: $payout->provider_error_code,
            providerHttpStatus: $payout->provider_http_status,
            correlationId: $reward->correlation_id,
        );
    }

    public function dispatch(CashbackPayoutSupportRequest $request): void
    {
        Context::scope(function () use ($request): void {
            try {
                Log::warning('cashback.payout.support_requested', [
                    'cashback_reward_id' => $request->cashbackRewardId,
                    'payout_id' => $request->payoutId,
                    'issue_category' => $request->issue->value,
                    'payout_status' => $request->payoutStatus->value,
                    'reward_status' => $request->rewardStatus->value,
                    'error_code' => $request->errorCode,
                    'provider_http_status' => $request->providerHttpStatus,
                    'correlation_id' => $request->correlationId,
                ]);
            } catch (Throwable $exception) {
                $this->reportSupportFailure($exception);
            }

            try {
                Notification::route('mail', $this->supportEmail)
                    ->notify(new CashbackPayoutRequiresAttention($request));
            } catch (Throwable $exception) {
                $this->reportSupportFailure($exception);
            }
        }, ['correlation_id' => $request->correlationId]);
    }

    private function issueFor(PayoutStatus $status): ?CashbackPayoutIssue
    {
        return match ($status) {
            PayoutStatus::InsufficientFunds => CashbackPayoutIssue::FundingRequired,
            PayoutStatus::Ambiguous => CashbackPayoutIssue::StatusUncertain,
            PayoutStatus::RetryableRejection => CashbackPayoutIssue::TemporaryRejection,
            PayoutStatus::PermanentRejection,
            PayoutStatus::OtpRequired,
            PayoutStatus::Failed,
            PayoutStatus::Reversed => CashbackPayoutIssue::HumanReview,
            PayoutStatus::Started,
            PayoutStatus::Pending,
            PayoutStatus::Succeeded => null,
        };
    }

    private function reportSupportFailure(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            /*
             * The request is already saved. Logging and queueing the email are
             * separate, so a failure in either must not undo or stop the other.
             */
        }
    }
}
