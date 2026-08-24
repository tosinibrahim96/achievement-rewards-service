<?php

declare(strict_types=1);

use App\Actions\Cashback\ProcessCashbackPayout;
use App\Actions\Cashback\RequestCashbackPayoutSupport;
use App\Contracts\Payments\CashbackTransferGateway;
use App\Data\Payments\CashbackTransferRequest;
use App\Data\Payments\CashbackTransferResult;
use App\Data\Payments\TransferBalance;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Exceptions\Payments\PaymentProviderException;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Infrastructure\Payments\PaymentProviderRegistry;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
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
}

/** @return array{CashbackReward, PayoutAccount} */
function payableCashbackReward(): array
{
    $user = User::factory()->create();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();
    $userBadge = UserBadge::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->readyForPayout()
        ->create();

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

it('commits a complete payout snapshot before provider work and does not preflight balance', function (): void {
    [$reward, $payoutAccount] = payableCashbackReward();
    $gateway = new InspectingCashbackTransferGateway(
        function (CashbackTransferRequest $request) use ($reward, $payoutAccount): void {
            $payout = Payout::query()->sole();

            expect(DB::connection()->transactionLevel())->toBe(0)
                ->and($payout->status)->toBe(PayoutStatus::Started)
                ->and($payout->cashback_reward_id)->toBe($reward->id)
                ->and($payout->payout_account_id)->toBe($payoutAccount->id)
                ->and($payout->provider)->toBe($payoutAccount->provider)
                ->and($payout->provider_reference)->toBe($reward->provider_reference)
                ->and($payout->provider_recipient_code)->toBe($payoutAccount->provider_recipient_code)
                ->and($payout->amount_minor)->toBe($reward->amount_minor)
                ->and($payout->currency)->toBe($reward->currency)
                ->and($request->providerReference)->toBe($payout->provider_reference)
                ->and($request->recipientCode)->toBe($payout->provider_recipient_code);
        },
        new CashbackTransferResult(
            status: PayoutStatus::Succeeded,
            transferCode: 'TRF_OBSERVED',
            latencyMs: 7,
        ),
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    $payout = $action->handle($reward->id);
    $reward->refresh();

    expect($gateway->balanceReads)->toBe(0)
        ->and($gateway->initiationCalls)->toBe(1)
        ->and($payout?->status)->toBe(PayoutStatus::Succeeded)
        ->and($payout?->provider_transfer_code)->toBe('TRF_OBSERVED')
        ->and($payout?->succeeded_at)->not->toBeNull()
        ->and($payout?->reversed_at)->toBeNull()
        ->and($reward->provider)->toBe(PaymentProvider::Fake)
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid)
        ->and($reward->paid_at)->not->toBeNull();
});

it('maps each basic fake outcome to its factual payout and obligation state', function (
    string $scenario,
    PayoutStatus $payoutStatus,
    CashbackRewardStatus $rewardStatus,
    bool $effectExists,
): void {
    config()->set('payments.fake.transfer_scenario', $scenario);
    [$reward] = payableCashbackReward();

    $payout = app(ProcessCashbackPayout::class)->handle($reward->id);
    $reward->refresh();
    $effects = app(FakeTransferEffectRegistry::class);
    $exists = (int) Redis::connection('default')->command('exists', [
        $effects->keyForReference($reward->provider_reference),
    ]);

    expect($payout?->status)->toBe($payoutStatus)
        ->and($reward->status)->toBe($rewardStatus)
        ->and($exists)->toBe($effectExists ? 1 : 0)
        ->and($payout?->completed_at)->not->toBeNull();

    if ($payoutStatus === PayoutStatus::Succeeded) {
        expect($payout?->succeeded_at)->not->toBeNull()
            ->and($reward->paid_at)->not->toBeNull();
    } else {
        expect($payout?->succeeded_at)->toBeNull()
            ->and($reward->paid_at)->toBeNull();
    }

    if ($payoutStatus === PayoutStatus::InsufficientFunds) {
        expect($payout?->observed_balance_minor)->toBe(0)
            ->and($reward->last_observed_balance_minor)->toBe(0)
            ->and($reward->balance_observed_at)->not->toBeNull();
    }
})->with([
    'success' => [
        'success',
        PayoutStatus::Succeeded,
        CashbackRewardStatus::Paid,
        true,
    ],
    'pending' => [
        'pending',
        PayoutStatus::Pending,
        CashbackRewardStatus::Pending,
        true,
    ],
    'insufficient funds' => [
        'insufficient_funds',
        PayoutStatus::InsufficientFunds,
        CashbackRewardStatus::AwaitingFunds,
        false,
    ],
    'permanent rejection' => [
        'permanent_failure',
        PayoutStatus::PermanentRejection,
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

    expect(app(ProcessCashbackPayout::class)->handle($reward->id))->toBeNull();

    $reward->refresh();

    expect($reward->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($reward->provider)->toBeNull()
        ->and($reward->last_attempted_at)->toBeNull()
        ->and($reward->payout()->exists())->toBeFalse();
});

it('does not claim an awaiting reward even when an account was inserted later', function (): void {
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request): void {},
        new CashbackTransferResult(PayoutStatus::Succeeded, 'TRF_NOT_CALLED'),
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect($action->handle($reward->id))->toBeNull()
        ->and($reward->refresh()->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($gateway->initiationCalls)->toBe(0)
        ->and(Payout::query()->count())->toBe(0);
});

it('does not claim a ready reward when its verified account is missing', function (): void {
    $reward = CashbackReward::factory()->readyForPayout()->create();
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request): void {},
        new CashbackTransferResult(PayoutStatus::Succeeded, 'TRF_NOT_CALLED'),
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect($action->handle($reward->id))->toBeNull()
        ->and($reward->refresh()->status)->toBe(CashbackRewardStatus::ReadyForPayout)
        ->and($gateway->initiationCalls)->toBe(0)
        ->and(Payout::query()->count())->toBe(0);
});

it('does not claim a ready reward with contradictory payout history', function (
    string $contradiction,
): void {
    [$reward, $account] = payableCashbackReward();

    if ($contradiction === 'provider') {
        $reward->update(['provider' => PaymentProvider::Fake]);
    } else {
        Payout::factory()->create([
            'cashback_reward_id' => $reward->id,
            'payout_account_id' => $account->id,
        ]);
    }

    $payoutCount = Payout::query()->where('cashback_reward_id', $reward->id)->count();
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request): void {},
        new CashbackTransferResult(PayoutStatus::Succeeded, 'TRF_NOT_CALLED'),
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect($action->handle($reward->id))->toBeNull()
        ->and($gateway->initiationCalls)->toBe(0)
        ->and(Payout::query()->where('cashback_reward_id', $reward->id)->count())
        ->toBe($payoutCount);
})->with([
    'provider already bound' => ['provider'],
    'payout already exists' => ['payout'],
]);

it('treats duplicate processing and every non-claimable state as a no-op', function (
    CashbackRewardStatus $status,
): void {
    [$reward] = payableCashbackReward();
    $reward->update(['status' => $status]);

    expect(app(ProcessCashbackPayout::class)->handle($reward->id))->toBeNull()
        ->and(Payout::query()->count())->toBe(0);
})->with([
    CashbackRewardStatus::AwaitingPayoutAccount,
    CashbackRewardStatus::Processing,
    CashbackRewardStatus::Pending,
    CashbackRewardStatus::AwaitingFunds,
    CashbackRewardStatus::Retrying,
    CashbackRewardStatus::Paid,
    CashbackRewardStatus::RequiresAttention,
]);

it('creates only one payout and one fake effect when the same reward is processed twice', function (): void {
    [$reward] = payableCashbackReward();
    $action = app(ProcessCashbackPayout::class);

    $first = $action->handle($reward->id);
    $second = $action->handle($reward->id);

    expect($first?->status)->toBe(PayoutStatus::Succeeded)
        ->and($second)->toBeNull()
        ->and(Payout::query()->whereBelongsTo($reward, 'cashbackReward')->count())->toBe(1)
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
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect(fn () => $action->handle($reward->id))->toThrow(
        RuntimeException::class,
        'Simulated worker failure after claim.',
    );

    $reward->refresh();
    $payout = Payout::query()->sole();

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($payout->status)->toBe(PayoutStatus::Started)
        ->and($payout->completed_at)->toBeNull()
        ->and($action->handle($reward->id))->toBeNull()
        ->and(Payout::query()->count())->toBe(1);
});

it('maps an unavailable persisted provider to attention without falling back', function (): void {
    [$reward] = payableCashbackReward();
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    $payout = $action->handle($reward->id);
    $reward->refresh();

    expect($payout?->status)->toBe(PayoutStatus::PermanentRejection)
        ->and($payout?->provider_error_code)->toBe('provider_unavailable')
        ->and($payout?->provider_transfer_code)->toBeNull()
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
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect(fn () => $action->handle($reward->id))->toThrow(PaymentProviderException::class);

    $reward->refresh();
    $payout = Payout::query()->sole();

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($reward->last_error_code)->toBeNull()
        ->and($payout->status)->toBe(PayoutStatus::Started)
        ->and($payout->completed_at)->toBeNull();
});

it('rejects invalid fake configuration before claiming a reward', function (): void {
    config()->set('payments.fake.transfer_scenario', 'not-a-scenario');
    [$reward] = payableCashbackReward();

    expect(fn () => app(ProcessCashbackPayout::class)->handle($reward->id))
        ->toThrow(PaymentProviderException::class);

    $reward->refresh();

    expect($reward->status)->toBe(CashbackRewardStatus::ReadyForPayout)
        ->and($reward->provider)->toBeNull()
        ->and($reward->last_attempted_at)->toBeNull()
        ->and(Payout::query()->count())->toBe(0);
});

it('does not blindly reinitiate after a fake effect exists but its response is lost', function (): void {
    [$reward] = payableCashbackReward();
    $effects = app(FakeTransferEffectRegistry::class);
    $gateway = new InspectingCashbackTransferGateway(
        static function (CashbackTransferRequest $request) use ($effects): void {
            $effects->create($request, PayoutStatus::Succeeded);
        },
        new RuntimeException('Simulated response loss after provider acceptance.'),
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    expect(fn () => $action->handle($reward->id))->toThrow(
        RuntimeException::class,
        'Simulated response loss after provider acceptance.',
    );

    $reward->refresh();
    $payout = Payout::query()->sole();

    expect($reward->status)->toBe(CashbackRewardStatus::Processing)
        ->and($payout->status)->toBe(PayoutStatus::Started)
        ->and($effects->findByReference($reward->provider_reference)?->status)
        ->toBe(PayoutStatus::Succeeded)
        ->and($action->handle($reward->id))->toBeNull()
        ->and($gateway->initiationCalls)->toBe(1);
});

it('does not overwrite a newer durable lifecycle fact with an older provider response', function (): void {
    [$reward] = payableCashbackReward();
    $gateway = new InspectingCashbackTransferGateway(
        function (CashbackTransferRequest $request) use ($reward): void {
            $observedAt = now();

            Payout::query()->where('cashback_reward_id', $reward->id)->update([
                'status' => PayoutStatus::Succeeded,
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
            status: PayoutStatus::Pending,
            transferCode: 'TRF_OLDER_RESPONSE',
        ),
    );
    $action = new ProcessCashbackPayout(
        new PaymentProviderRegistry([], [$gateway], 'fake'),
        app(RequestCashbackPayoutSupport::class),
    );

    $payout = $action->handle($reward->id);
    $reward->refresh();

    expect($payout?->status)->toBe(PayoutStatus::Succeeded)
        ->and($payout?->provider_transfer_code)->toBe('TRF_CALLBACK_WON')
        ->and($reward->status)->toBe(CashbackRewardStatus::Paid)
        ->and($reward->paid_at)->not->toBeNull();
});

it('does not let account replacement or a default-driver change redirect a claimed payout', function (): void {
    config()->set('payments.fake.transfer_scenario', 'pending');
    [$reward, $account] = payableCashbackReward();

    app(ProcessCashbackPayout::class)->handle($reward->id);
    $originalPayout = Payout::query()->sole();
    $originalSnapshot = [
        $originalPayout->provider,
        $originalPayout->provider_recipient_code,
        $originalPayout->payout_account_id,
    ];

    config()->set('payments.default', PaymentProvider::Paystack->value);
    $account->update([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => 'RCP_PAYSTACK_REPLACEMENT',
    ]);

    expect(app(ProcessCashbackPayout::class)->handle($reward->id))->toBeNull();

    $originalPayout->refresh();

    expect([
        $originalPayout->provider,
        $originalPayout->provider_recipient_code,
        $originalPayout->payout_account_id,
    ])->toBe($originalSnapshot)
        ->and(Payout::query()->count())->toBe(1);
});

it('rejects caller-owned transactions before creating a payout', function (): void {
    [$reward] = payableCashbackReward();

    expect(fn () => DB::transaction(
        fn () => app(ProcessCashbackPayout::class)->handle($reward->id),
    ))->toThrow(
        LogicException::class,
        'Cashback payout processing cannot run inside an existing database transaction.',
    );

    expect(Payout::query()->count())->toBe(0);
});
