<?php

declare(strict_types=1);

use App\Actions\Cashback\ProcessCashbackPayment;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\CashbackTransferVerification;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackPayoutIssue;
use App\Enums\CashbackRewardStatus;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Models\UserBadge;
use App\Notifications\CashbackPayoutRequiresAttention;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Mockery;
use RuntimeException;

uses(DatabaseMigrations::class);

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

    public function verifyTransfer(string $providerReference): CashbackTransferVerification
    {
        return new CashbackTransferVerification(null);
    }
}

/** @return array{CashbackReward, PayoutAccount} */
function rewardForPayoutSupportTest(): array
{
    $user = User::factory()->create(['email' => 'private-customer@example.test']);
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();
    $account = PayoutAccount::factory()->for($user)->create([
        'provider_recipient_code' => 'RCP_PRIVATE_DESTINATION',
    ]);

    return [$reward, $account];
}

function processSupportOutcome(
    CashbackReward $reward,
    CashbackTransferResult $result,
): ?PayoutAttempt {
    return (new ProcessCashbackPayment(
        new PaymentProviderRegistry(
            [],
            [new FixedSupportOutcomeGateway($result)],
            PaymentProvider::Fake->value,
        ),
        app(RequestCashbackPayoutSupport::class),
    ))->handle($reward->id);
}

beforeEach(function (): void {
    config()->set('rewards.support_email', 'support@example.test');
    Notification::fake();
});

it('maps each unresolved initial outcome to one safe support category', function (
    CashbackTransferResult $result,
    CashbackPayoutIssue $expectedIssue,
    CashbackRewardStatus $expectedRewardStatus,
): void {
    [$reward, $account] = rewardForPayoutSupportTest();

    $attempt = processSupportOutcome($reward, $result);
    $reward->refresh();

    expect($attempt)->not->toBeNull()
        ->and($attempt?->support_alert_requested_at)->not->toBeNull()
        ->and($reward->status)->toBe($expectedRewardStatus);
    Notification::assertSentOnDemand(
        CashbackPayoutRequiresAttention::class,
        function (
            CashbackPayoutRequiresAttention $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ) use ($reward, $attempt, $account, $expectedIssue): bool {
            $mail = $notification->toMail($notifiable);
            $content = implode(' ', $mail->introLines);

            return $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'support@example.test'
                && $notification instanceof ShouldQueueAfterCommit
                && str_contains($content, "Cashback reward #{$reward->id}")
                && str_contains($content, "Payout attempt: #{$attempt?->id}")
                && str_contains($content, "Issue: {$expectedIssue->value}")
                && str_contains($content, $expectedIssue->reason())
                && str_contains($content, $expectedIssue->nextAction())
                && ! str_contains($content, $reward->provider_reference)
                && ! str_contains($content, $account->provider_recipient_code)
                && ! str_contains($content, 'private-customer@example.test')
                && ! str_contains($content, 'PRIVATE_PROVIDER_REASON');
        },
    );
    Notification::assertSentOnDemandTimes(CashbackPayoutRequiresAttention::class, 1);
})->with([
    'funding required' => [
        new CashbackTransferResult(
            status: PayoutAttemptStatus::InsufficientFunds,
            errorCode: CashbackTransferErrorCode::InsufficientFunds,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
            observedBalanceMinor: 0,
        ),
        CashbackPayoutIssue::FundingRequired,
        CashbackRewardStatus::AwaitingFunds,
    ],
    'status uncertain' => [
        new CashbackTransferResult(
            status: PayoutAttemptStatus::Ambiguous,
            errorCode: CashbackTransferErrorCode::ProviderTimeout,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
        ),
        CashbackPayoutIssue::StatusUncertain,
        CashbackRewardStatus::Processing,
    ],
    'temporary rejection' => [
        new CashbackTransferResult(
            status: PayoutAttemptStatus::RetryableRejection,
            errorCode: CashbackTransferErrorCode::RateLimited,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
        ),
        CashbackPayoutIssue::TemporaryRejection,
        CashbackRewardStatus::RequiresAttention,
    ],
    'human review' => [
        new CashbackTransferResult(
            status: PayoutAttemptStatus::PermanentRejection,
            errorCode: CashbackTransferErrorCode::PermanentFailure,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
        ),
        CashbackPayoutIssue::HumanReview,
        CashbackRewardStatus::RequiresAttention,
    ],
]);

it('does not alert for pending or successful outcomes', function (
    CashbackTransferResult $result,
): void {
    [$reward] = rewardForPayoutSupportTest();

    $attempt = processSupportOutcome($reward, $result);

    expect($attempt?->support_alert_requested_at)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'pending' => [new CashbackTransferResult(
        status: PayoutAttemptStatus::Pending,
        transferCode: 'TRF_PENDING_SUPPORT_TEST',
    )],
    'success' => [new CashbackTransferResult(
        status: PayoutAttemptStatus::Succeeded,
        transferCode: 'TRF_SUCCESS_SUPPORT_TEST',
    )],
]);

it('requests support only once when the same payout work is delivered again', function (): void {
    [$reward] = rewardForPayoutSupportTest();
    $result = new CashbackTransferResult(
        status: PayoutAttemptStatus::Ambiguous,
        errorCode: CashbackTransferErrorCode::ProviderTimeout,
        errorMessage: 'The provider outcome could not be confirmed.',
    );

    $first = processSupportOutcome($reward, $result);
    $second = processSupportOutcome($reward, $result);

    expect($first?->support_alert_requested_at)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(PayoutAttempt::query()->count())->toBe(1);
    Notification::assertSentOnDemandTimes(CashbackPayoutRequiresAttention::class, 1);
});

it('logs payout processing and support intent with exact safe allowlists and scoped correlation', function (): void {
    [$reward] = rewardForPayoutSupportTest();
    $result = new CashbackTransferResult(
        status: PayoutAttemptStatus::RetryableRejection,
        httpStatus: 429,
        errorCode: CashbackTransferErrorCode::RateLimited,
        errorMessage: 'PRIVATE_PROVIDER_REASON',
        latencyMs: 17,
    );

    Log::shouldReceive('info')->once()->with(
        'cashback.payout.processed',
        Mockery::on(function (array $context) use ($reward): bool {
            return array_keys($context) === [
                'cashback_reward_id',
                'payout_attempt_id',
                'provider',
                'state_changed',
                'attempt_status',
                'reward_status',
                'error_code',
                'provider_http_status',
                'provider_latency_ms',
                'correlation_id',
            ]
                && $context['cashback_reward_id'] === $reward->id
                && $context['state_changed'] === true
                && $context['attempt_status'] === PayoutAttemptStatus::RetryableRejection->value
                && $context['reward_status'] === CashbackRewardStatus::RequiresAttention->value
                && $context['error_code'] === CashbackTransferErrorCode::RateLimited->value
                && $context['provider_http_status'] === 429
                && $context['provider_latency_ms'] === 17
                && Context::get('correlation_id') === $reward->correlation_id;
        }),
    );
    Log::shouldReceive('warning')->once()->with(
        'cashback.payout.support_requested',
        Mockery::on(function (array $context) use ($reward): bool {
            return array_keys($context) === [
                'cashback_reward_id',
                'payout_attempt_id',
                'issue_category',
                'attempt_status',
                'reward_status',
                'error_code',
                'provider_http_status',
                'correlation_id',
            ]
                && $context['cashback_reward_id'] === $reward->id
                && $context['issue_category'] === CashbackPayoutIssue::TemporaryRejection->value
                && $context['correlation_id'] === $reward->correlation_id
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $reward->provider_reference)
                && Context::get('correlation_id') === $reward->correlation_id;
        }),
    );

    processSupportOutcome($reward, $result);
});

it('keeps committed state and warning evidence when the notification queue push fails', function (): void {
    Log::spy();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);
    [$reward] = rewardForPayoutSupportTest();
    $result = new CashbackTransferResult(
        status: PayoutAttemptStatus::Ambiguous,
        errorCode: CashbackTransferErrorCode::ProviderTimeout,
        errorMessage: 'The provider outcome could not be confirmed.',
    );

    $attempt = processSupportOutcome($reward, $result);
    $reward->refresh();

    expect($attempt?->support_alert_requested_at)->not->toBeNull()
        ->and($reward->status)->toBe(CashbackRewardStatus::Processing);
    Log::shouldHaveReceived('warning')->with(
        'cashback.payout.support_requested',
        Mockery::type('array'),
    )->once();
});

it('still attempts notification when the post-commit support log fails', function (): void {
    Log::spy();
    Log::shouldReceive('warning')
        ->once()
        ->with('cashback.payout.support_requested', Mockery::type('array'))
        ->andThrow(new RuntimeException('log unavailable'));
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->once();
    app()->instance(Dispatcher::class, $dispatcher);
    [$reward] = rewardForPayoutSupportTest();
    $result = new CashbackTransferResult(
        status: PayoutAttemptStatus::PermanentRejection,
        errorCode: CashbackTransferErrorCode::PermanentFailure,
        errorMessage: 'A safe permanent failure.',
    );

    $attempt = processSupportOutcome($reward, $result);

    expect($attempt?->support_alert_requested_at)->not->toBeNull();
});

it('requires the alert stamp to be selected while the payout row is transaction-locked', function (): void {
    $attempt = PayoutAttempt::factory()->create();

    expect(fn () => app(RequestCashbackPayoutSupport::class)->markWhileLocked(
        $attempt->cashbackReward,
        $attempt,
    ))->toThrow(
        LogicException::class,
        'A support request must be marked inside the payout transaction.',
    );
});
