<?php

declare(strict_types=1);

use App\Actions\Cashback\ProcessCashbackPayout;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Cashback\CashbackPayoutSupportRequest;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackPayoutIssue;
use App\Enums\CashbackRewardStatus;
use App\Enums\CashbackTransferErrorCode;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use App\Notifications\CashbackPayoutRequiresAttention;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
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
}

/** @return array{CashbackReward, PayoutAccount} */
function rewardForPayoutSupportTest(): array
{
    $user = User::factory()->create(['email' => 'private-customer@example.test']);
    $account = PayoutAccount::factory()->for($user)->create([
        'provider_recipient_code' => 'RCP_PRIVATE_DESTINATION',
    ]);
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->readyForPayout()
        ->create();

    return [$reward, $account];
}

function processSupportOutcome(
    CashbackReward $reward,
    CashbackTransferResult $result,
): ?Payout {
    return (new ProcessCashbackPayout(
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

it('gives support the real review path for an uncertain Paystack payout', function (): void {
    expect(CashbackPayoutIssue::StatusUncertain->nextAction())->toBe(
        'Wait for a matching callback; if none arrives, inspect the existing transfer in Paystack and resolve the customer\'s outstanding reward.',
    );
});

it('maps each unresolved initial outcome to one safe support category', function (
    CashbackTransferResult $result,
    CashbackPayoutIssue $expectedIssue,
    CashbackRewardStatus $expectedRewardStatus,
): void {
    [$reward, $account] = rewardForPayoutSupportTest();

    $payout = processSupportOutcome($reward, $result);
    $reward->refresh();

    expect($payout)->not->toBeNull()
        ->and($payout?->support_alert_requested_at)->not->toBeNull()
        ->and($reward->status)->toBe($expectedRewardStatus);
    Notification::assertSentOnDemand(
        CashbackPayoutRequiresAttention::class,
        function (
            CashbackPayoutRequiresAttention $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ) use ($reward, $payout, $account, $expectedIssue): bool {
            $mail = $notification->toMail($notifiable);
            $content = implode(' ', $mail->introLines);

            return $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'support@example.test'
                && $notification instanceof ShouldQueueAfterCommit
                && str_contains($content, "Cashback reward #{$reward->id}")
                && str_contains($content, "Payout: #{$payout?->id}")
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
            status: PayoutStatus::InsufficientFunds,
            errorCode: CashbackTransferErrorCode::InsufficientFunds,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
            observedBalanceMinor: 0,
        ),
        CashbackPayoutIssue::FundingRequired,
        CashbackRewardStatus::AwaitingFunds,
    ],
    'status uncertain' => [
        new CashbackTransferResult(
            status: PayoutStatus::Ambiguous,
            errorCode: CashbackTransferErrorCode::ProviderTimeout,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
        ),
        CashbackPayoutIssue::StatusUncertain,
        CashbackRewardStatus::Processing,
    ],
    'rate limited' => [
        new CashbackTransferResult(
            status: PayoutStatus::RateLimited,
            errorCode: CashbackTransferErrorCode::RateLimited,
            errorMessage: 'PRIVATE_PROVIDER_REASON',
        ),
        CashbackPayoutIssue::RateLimited,
        CashbackRewardStatus::RequiresAttention,
    ],
    'human review' => [
        new CashbackTransferResult(
            status: PayoutStatus::Rejected,
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

    $payout = processSupportOutcome($reward, $result);

    expect($payout?->support_alert_requested_at)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'pending' => [new CashbackTransferResult(
        status: PayoutStatus::Pending,
        transferCode: 'TRF_PENDING_SUPPORT_TEST',
    )],
    'success' => [new CashbackTransferResult(
        status: PayoutStatus::Succeeded,
        transferCode: 'TRF_SUCCESS_SUPPORT_TEST',
    )],
]);

it('requests support only once when the same payout work is delivered again', function (): void {
    [$reward] = rewardForPayoutSupportTest();
    $result = new CashbackTransferResult(
        status: PayoutStatus::Ambiguous,
        errorCode: CashbackTransferErrorCode::ProviderTimeout,
        errorMessage: 'The provider outcome could not be confirmed.',
    );

    $first = processSupportOutcome($reward, $result);
    $second = processSupportOutcome($reward, $result);

    expect($first?->support_alert_requested_at)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(Payout::query()->count())->toBe(1);
    Notification::assertSentOnDemandTimes(CashbackPayoutRequiresAttention::class, 1);
});

it('logs payout processing and support intent with exact safe allowlists and scoped correlation', function (): void {
    [$reward] = rewardForPayoutSupportTest();
    $result = new CashbackTransferResult(
        status: PayoutStatus::RateLimited,
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
                'payout_id',
                'provider',
                'state_changed',
                'payout_status',
                'reward_status',
                'error_code',
                'provider_http_status',
                'provider_latency_ms',
                'correlation_id',
            ]
                && $context['cashback_reward_id'] === $reward->id
                && $context['state_changed'] === true
                && $context['payout_status'] === PayoutStatus::RateLimited->value
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
                'payout_id',
                'issue_category',
                'payout_status',
                'reward_status',
                'error_code',
                'provider_http_status',
                'correlation_id',
            ]
                && $context['cashback_reward_id'] === $reward->id
                && $context['issue_category'] === CashbackPayoutIssue::RateLimited->value
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
        status: PayoutStatus::Ambiguous,
        errorCode: CashbackTransferErrorCode::ProviderTimeout,
        errorMessage: 'The provider outcome could not be confirmed.',
    );

    $payout = processSupportOutcome($reward, $result);
    $reward->refresh();

    expect($payout?->support_alert_requested_at)->not->toBeNull()
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
        status: PayoutStatus::Rejected,
        errorCode: CashbackTransferErrorCode::PermanentFailure,
        errorMessage: 'A safe permanent failure.',
    );

    $payout = processSupportOutcome($reward, $result);

    expect($payout?->support_alert_requested_at)->not->toBeNull();
});

it('requires the alert stamp to be selected while the payout row is transaction-locked', function (): void {
    $payout = Payout::factory()->create();

    expect(fn () => app(RequestCashbackPayoutSupport::class)->markWhileLocked(
        $payout->cashbackReward,
        $payout,
    ))->toThrow(
        LogicException::class,
        'A support request must be marked inside the payout transaction.',
    );
});

it('round trips renamed payout enums through real database queue payloads', function (): void {
    $notifiable = (new AnonymousNotifiable)->route('mail', 'support@example.test');
    $requests = [
        new CashbackPayoutSupportRequest(
            cashbackRewardId: 41,
            payoutId: 51,
            issue: CashbackPayoutIssue::RateLimited,
            payoutStatus: PayoutStatus::RateLimited,
            rewardStatus: CashbackRewardStatus::RequiresAttention,
            errorCode: CashbackTransferErrorCode::RateLimited->value,
            providerHttpStatus: 429,
            correlationId: '01QUEUE_RATE_LIMITED_ROUND_TRIP',
        ),
        new CashbackPayoutSupportRequest(
            cashbackRewardId: 42,
            payoutId: 52,
            issue: CashbackPayoutIssue::HumanReview,
            payoutStatus: PayoutStatus::Rejected,
            rewardStatus: CashbackRewardStatus::RequiresAttention,
            errorCode: CashbackTransferErrorCode::PermanentFailure->value,
            providerHttpStatus: 400,
            correlationId: '01QUEUE_REJECTED_ROUND_TRIP',
        ),
    ];

    foreach ($requests as $request) {
        app(QueueFactory::class)->connection('database')->push(new SendQueuedNotifications(
            $notifiable,
            new CashbackPayoutRequiresAttention($request),
            ['mail'],
        ));
    }

    $commands = DB::table('jobs')
        ->orderBy('id')
        ->pluck('payload')
        ->map(static function (string $payload): string {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $command = $decoded['data']['command'] ?? null;

            if (! is_string($command)) {
                throw new RuntimeException('The database queue payload must contain a serialized command.');
            }

            return $command;
        });

    expect($commands)->toHaveCount(2)
        ->and($commands[0])->toContain('App\\Enums\\CashbackPayoutIssue:RateLimited')
        ->toContain('App\\Enums\\PayoutStatus:RateLimited')
        ->not->toContain('RetryableRejection', 'TemporaryRejection')
        ->and($commands[1])->toContain('App\\Enums\\PayoutStatus:Rejected')
        ->not->toContain('PermanentRejection')
        ->and(unserialize($commands[0], ['allowed_classes' => true]))
        ->toBeInstanceOf(SendQueuedNotifications::class)
        ->and(unserialize($commands[1], ['allowed_classes' => true]))
        ->toBeInstanceOf(SendQueuedNotifications::class);
});
