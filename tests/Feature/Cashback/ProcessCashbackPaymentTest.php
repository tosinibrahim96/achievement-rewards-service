<?php

declare(strict_types=1);

use App\Actions\Cashback\ProcessCashbackPayment;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\CashbackTransferVerification;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Models\UserBadge;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

uses(DatabaseMigrations::class);

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

    public function verifyTransfer(string $providerReference): CashbackTransferVerification
    {
        return new CashbackTransferVerification(null);
    }
}

/** @return array{CashbackReward, PayoutAccount} */
function payableCashbackReward(): array
{
    $user = User::factory()->create();
    $userBadge = UserBadge::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->create();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();

    return [$reward, $payoutAccount];
}

beforeEach(function (): void {
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.transfer_scenario', 'success');
    config()->set(
        'payments.fake.transfer_effect_namespace',
        'pest-'.Str::lower((string) Str::ulid()),
    );
});

afterEach(function (): void {
    $namespace = config('payments.fake.transfer_effect_namespace');

    if (! is_string($namespace)) {
        return;
    }

    $effects = new FakeTransferEffectRegistry('testing', $namespace);

    CashbackReward::query()->pluck('provider_reference')->each(
        static fn (string $reference) => $effects->forget($reference),
    );
});

it('commits a complete attempt snapshot before provider work and does not preflight balance', function (): void {
    [$reward, $payoutAccount] = payableCashbackReward();
    $gateway = new InspectingCashbackTransferGateway(
        function (CashbackTransferRequest $request) use ($reward, $payoutAccount): void {
            $attempt = PayoutAttempt::query()->sole();

            expect(DB::connection()->transactionLevel())->toBe(0)
                ->and($attempt->status)->toBe(PayoutAttemptStatus::Started)
                ->and($attempt->cashback_reward_id)->toBe($reward->id)
                ->and($attempt->payout_account_id)->toBe($payoutAccount->id)
                ->and($attempt->provider)->toBe($payoutAccount->provider)
                ->and($attempt->provider_reference)->toBe($reward->provider_reference)
                ->and($attempt->provider_recipient_code)->toBe($payoutAccount->provider_recipient_code)
                ->and($attempt->amount_minor)->toBe($reward->amount_minor)
                ->and($attempt->currency)->toBe($reward->currency)
                ->and($request->providerReference)->toBe($attempt->provider_reference)
                ->and($request->recipientCode)->toBe($attempt->provider_recipient_code);
        },
        new CashbackTransferResult(
            status: PayoutAttemptStatus::Succeeded,
            transferCode: 'TRF_OBSERVED',
            latencyMs: 7,
        ),
    );
    $action = new ProcessCashbackPayment(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    $attempt = $action->handle($reward->id);
    $reward->refresh();

    expect($gateway->balanceReads)->toBe(0)
        ->and($gateway->initiationCalls)->toBe(1)
        ->and($attempt?->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($attempt?->provider_transfer_code)->toBe('TRF_OBSERVED')
        ->and($attempt?->succeeded_at)->not->toBeNull()
        ->and($attempt?->reversed_at)->toBeNull()
        ->and($reward->provider)->toBe(PaymentProvider::Fake)
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid)
        ->and($reward->paid_at)->not->toBeNull();
});

it('maps each basic fake outcome to its factual attempt and obligation state', function (
    string $scenario,
    PayoutAttemptStatus $attemptStatus,
    CashbackRewardStatus $rewardStatus,
    bool $effectExists,
): void {
    config()->set('payments.fake.transfer_scenario', $scenario);
    [$reward] = payableCashbackReward();

    $attempt = app(ProcessCashbackPayment::class)->handle($reward->id);
    $reward->refresh();
    $effects = app(FakeTransferEffectRegistry::class);
    $exists = (int) Redis::connection('default')->command('exists', [
        $effects->keyForReference($reward->provider_reference),
    ]);

    expect($attempt?->status)->toBe($attemptStatus)
        ->and($reward->status)->toBe($rewardStatus)
        ->and($exists)->toBe($effectExists ? 1 : 0)
        ->and($attempt?->completed_at)->not->toBeNull();

    if ($attemptStatus === PayoutAttemptStatus::Succeeded) {
        expect($attempt?->succeeded_at)->not->toBeNull()
            ->and($reward->paid_at)->not->toBeNull();
    } else {
        expect($attempt?->succeeded_at)->toBeNull()
            ->and($reward->paid_at)->toBeNull();
    }

    if ($attemptStatus === PayoutAttemptStatus::InsufficientFunds) {
        expect($attempt?->observed_balance_minor)->toBe(0)
            ->and($reward->last_observed_balance_minor)->toBe(0)
            ->and($reward->balance_observed_at)->not->toBeNull();
    }
})->with([
    'success' => [
        'success',
        PayoutAttemptStatus::Succeeded,
        CashbackRewardStatus::Paid,
        true,
    ],
    'pending' => [
        'pending',
        PayoutAttemptStatus::Pending,
        CashbackRewardStatus::Pending,
        true,
    ],
    'insufficient funds' => [
        'insufficient_funds',
        PayoutAttemptStatus::InsufficientFunds,
        CashbackRewardStatus::AwaitingFunds,
        false,
    ],
    'permanent rejection' => [
        'permanent_failure',
        PayoutAttemptStatus::PermanentRejection,
        CashbackRewardStatus::RequiresAttention,
        false,
    ],
]);

it('leaves a reward untouched when no verified payout account exists', function (): void {
    $user = User::factory()->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();

    expect(app(ProcessCashbackPayment::class)->handle($reward->id))->toBeNull();

    $reward->refresh();

    expect($reward->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($reward->provider)->toBeNull()
        ->and($reward->last_attempted_at)->toBeNull()
        ->and($reward->payoutAttempts()->count())->toBe(0);
});

it('treats duplicate processing and every non-claimable state as a no-op', function (
    CashbackRewardStatus $status,
): void {
    [$reward] = payableCashbackReward();
    $reward->update(['status' => $status]);

    expect(app(ProcessCashbackPayment::class)->handle($reward->id))->toBeNull()
        ->and(PayoutAttempt::query()->count())->toBe(0);
})->with([
    CashbackRewardStatus::Processing,
    CashbackRewardStatus::Pending,
    CashbackRewardStatus::AwaitingFunds,
    CashbackRewardStatus::Retrying,
    CashbackRewardStatus::Paid,
    CashbackRewardStatus::RequiresAttention,
]);

it('creates only one attempt and one fake effect when the same reward is processed twice', function (): void {
    [$reward] = payableCashbackReward();
    $action = app(ProcessCashbackPayment::class);

    $first = $action->handle($reward->id);
    $second = $action->handle($reward->id);

    expect($first?->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($second)->toBeNull()
        ->and(PayoutAttempt::query()->whereBelongsTo($reward, 'cashbackReward')->count())->toBe(1)
        ->and((int) Redis::connection('default')->command('exists', [
            app(FakeTransferEffectRegistry::class)->keyForReference($reward->provider_reference),
        ]))->toBe(1);
});

it('keeps the durable claim when the worker fails after the claim commit', function (): void {
    [$reward] = payableCashbackReward();
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request): void {
            /*
             * Reaching the provider boundary is sufficient for this crash simulation.
             */
        },
        new RuntimeException('Simulated worker failure after claim.'),
    );
    $action = new ProcessCashbackPayment(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect(fn () => $action->handle($reward->id))->toThrow(
        RuntimeException::class,
        'Simulated worker failure after claim.',
    );

    $reward->refresh();
    $attempt = PayoutAttempt::query()->sole();

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($attempt->status)->toBe(PayoutAttemptStatus::Started)
        ->and($attempt->completed_at)->toBeNull()
        ->and($action->handle($reward->id))->toBeNull()
        ->and(PayoutAttempt::query()->count())->toBe(1);
});

it('maps an unavailable persisted provider to attention without falling back', function (): void {
    [$reward] = payableCashbackReward();
    $action = new ProcessCashbackPayment(
        new PaymentProviderRegistry([], [], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    $attempt = $action->handle($reward->id);
    $reward->refresh();

    expect($attempt?->status)->toBe(PayoutAttemptStatus::PermanentRejection)
        ->and($attempt?->provider_error_code)->toBe('provider_unavailable')
        ->and($attempt?->provider_transfer_code)->toBeNull()
        ->and($reward->provider)->toBe(PaymentProvider::Fake)
        ->and($reward->status)->toBe(CashbackRewardStatus::RequiresAttention)
        ->and($reward->last_error_message)->toBe('The persisted payment provider is unavailable.');
});

it('keeps a registered gateway failure discoverable instead of calling it a conclusive rejection', function (): void {
    [$reward] = payableCashbackReward();
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request): void {
            /*
             * Reaching this callback proves that registry lookup itself succeeded.
             */
        },
        PaymentProviderException::unavailable(),
    );
    $action = new ProcessCashbackPayment(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect(fn () => $action->handle($reward->id))->toThrow(PaymentProviderException::class);

    $reward->refresh();
    $attempt = PayoutAttempt::query()->sole();

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($reward->last_error_code)->toBeNull()
        ->and($attempt->status)->toBe(PayoutAttemptStatus::Started)
        ->and($attempt->completed_at)->toBeNull();
});

it('rejects invalid fake configuration before claiming a reward', function (): void {
    config()->set('payments.fake.transfer_scenario', 'not-a-scenario');
    [$reward] = payableCashbackReward();

    expect(fn () => app(ProcessCashbackPayment::class)->handle($reward->id))
        ->toThrow(PaymentProviderException::class);

    $reward->refresh();

    expect($reward->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($reward->provider)->toBeNull()
        ->and($reward->last_attempted_at)->toBeNull()
        ->and(PayoutAttempt::query()->count())->toBe(0);
});

it('does not blindly reinitiate after a fake effect exists but its response is lost', function (): void {
    [$reward] = payableCashbackReward();
    $effects = app(FakeTransferEffectRegistry::class);
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request) use ($effects): void {
            $effects->create($request, PayoutAttemptStatus::Succeeded);
        },
        new RuntimeException('Simulated response loss after provider acceptance.'),
    );
    $action = new ProcessCashbackPayment(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect(fn () => $action->handle($reward->id))->toThrow(
        RuntimeException::class,
        'Simulated response loss after provider acceptance.',
    );

    $reward->refresh();
    $attempt = PayoutAttempt::query()->sole();

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($attempt->status)->toBe(PayoutAttemptStatus::Started)
        ->and($effects->findByReference($reward->provider_reference)?->status)
        ->toBe(PayoutAttemptStatus::Succeeded)
        ->and($action->handle($reward->id))->toBeNull()
        ->and($gateway->initiationCalls)->toBe(1);
});

it('does not overwrite a newer durable lifecycle fact with an older provider response', function (): void {
    [$reward] = payableCashbackReward();
    $gateway = new InspectingCashbackTransferGateway(
        function (CashbackTransferRequest $request) use ($reward): void {
            $observedAt = now();

            PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->update([
                'status' => PayoutAttemptStatus::Succeeded,
                'provider_transfer_code' => 'TRF_CALLBACK_WON',
                'succeeded_at' => $observedAt,
                'completed_at' => $observedAt,
            ]);
            CashbackReward::query()->whereKey($reward->id)->update([
                'status' => CashbackRewardStatus::Paid,
                'paid_at' => $observedAt,
            ]);
        },
        new CashbackTransferResult(
            status: PayoutAttemptStatus::Pending,
            transferCode: 'TRF_OLDER_RESPONSE',
        ),
    );
    $action = new ProcessCashbackPayment(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    $attempt = $action->handle($reward->id);
    $reward->refresh();

    expect($attempt?->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($attempt?->provider_transfer_code)->toBe('TRF_CALLBACK_WON')
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid)
        ->and($reward->paid_at)->not->toBeNull();
});

it('does not let account replacement or a default-driver change redirect a claimed attempt', function (): void {
    config()->set('payments.fake.transfer_scenario', 'pending');
    [$reward, $account] = payableCashbackReward();

    app(ProcessCashbackPayment::class)->handle($reward->id);
    $originalAttempt = PayoutAttempt::query()->sole();
    $originalSnapshot = [
        $originalAttempt->provider,
        $originalAttempt->provider_recipient_code,
        $originalAttempt->payout_account_id,
    ];

    config()->set('payments.default', PaymentProvider::Paystack->value);
    $account->update([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => 'RCP_PAYSTACK_REPLACEMENT',
    ]);

    expect(app(ProcessCashbackPayment::class)->handle($reward->id))->toBeNull();

    $originalAttempt->refresh();

    expect([
        $originalAttempt->provider,
        $originalAttempt->provider_recipient_code,
        $originalAttempt->payout_account_id,
    ])->toBe($originalSnapshot)
        ->and(PayoutAttempt::query()->count())->toBe(1);
});

it('rejects caller-owned transactions before creating an attempt', function (): void {
    [$reward] = payableCashbackReward();

    expect(fn () => DB::transaction(
        fn () => app(ProcessCashbackPayment::class)->handle($reward->id),
    ))->toThrow(
        LogicException::class,
        'Cashback payment processing cannot run inside an existing database transaction.',
    );

    expect(PayoutAttempt::query()->count())->toBe(0);
});
