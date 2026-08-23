<?php

declare(strict_types=1);

namespace App\Actions\Cashback;

use App\Data\Cashback\CashbackPayoutSupportRequest;
use App\Enums\CashbackPayoutIssue;
use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutAttemptStatus;
use App\Models\CashbackReward;
use App\Models\PayoutAttempt;
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
        #[SensitiveParameter] PayoutAttempt $attempt,
    ): ?CashbackPayoutSupportRequest {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('A support request must be marked inside the payout transaction.');
        }

        $issue = $this->issueFor($attempt->status);

        if ($attempt->cashback_reward_id !== $reward->id
            || $attempt->support_alert_requested_at !== null
            || $issue === null
            || ! in_array($reward->status, [
                CashbackRewardStatus::AwaitingFunds,
                CashbackRewardStatus::Processing,
                CashbackRewardStatus::RequiresAttention,
            ], true)) {
            return null;
        }

        $attempt->support_alert_requested_at = now()->toImmutable();

        return new CashbackPayoutSupportRequest(
            cashbackRewardId: $reward->id,
            payoutAttemptId: $attempt->id,
            issue: $issue,
            attemptStatus: $attempt->status,
            rewardStatus: $reward->status,
            errorCode: $attempt->provider_error_code,
            providerHttpStatus: $attempt->provider_http_status,
            correlationId: $reward->correlation_id,
        );
    }

    public function dispatch(CashbackPayoutSupportRequest $request): void
    {
        Context::scope(function () use ($request): void {
            try {
                Log::warning('cashback.payout.support_requested', [
                    'cashback_reward_id' => $request->cashbackRewardId,
                    'payout_attempt_id' => $request->payoutAttemptId,
                    'issue_category' => $request->issue->value,
                    'attempt_status' => $request->attemptStatus->value,
                    'reward_status' => $request->rewardStatus->value,
                    'error_code' => $request->errorCode,
                    'provider_http_status' => $request->providerHttpStatus,
                    'correlation_id' => $request->correlationId,
                ]);
            } catch (Throwable $exception) {
                $this->reportSafely($exception);
            }

            try {
                Notification::route('mail', $this->supportEmail)
                    ->notify(new CashbackPayoutRequiresAttention($request));
            } catch (Throwable $exception) {
                $this->reportSafely($exception);
            }
        }, ['correlation_id' => $request->correlationId]);
    }

    private function issueFor(PayoutAttemptStatus $status): ?CashbackPayoutIssue
    {
        return match ($status) {
            PayoutAttemptStatus::InsufficientFunds => CashbackPayoutIssue::FundingRequired,
            PayoutAttemptStatus::Ambiguous => CashbackPayoutIssue::StatusUncertain,
            PayoutAttemptStatus::RetryableRejection => CashbackPayoutIssue::TemporaryRejection,
            PayoutAttemptStatus::PermanentRejection,
            PayoutAttemptStatus::OtpRequired,
            PayoutAttemptStatus::Failed,
            PayoutAttemptStatus::Reversed => CashbackPayoutIssue::HumanReview,
            PayoutAttemptStatus::Started,
            PayoutAttemptStatus::Pending,
            PayoutAttemptStatus::Succeeded => null,
        };
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // State is already committed; reporting cannot make queueing or delivery atomic.
        }
    }
}
