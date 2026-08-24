<?php

declare(strict_types=1);

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\CashbackRewardStatus;
use App\Enums\Currency;
use App\Enums\PaymentProvider;
use App\Enums\PaymentProviderFailure;
use App\Events\PayoutAccountVerified;
use App\Exceptions\Payments\PaymentProviderException;
use App\Exceptions\Payouts\PayoutAccountConflictException;
use App\Infrastructure\Payments\FakeTransferRecipientGateway;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('app.key', 'base64:payout-account-test-key');
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.payout_account_scenario', 'success');
});

/** @param array<string, mixed> $attributes */
function payoutAccountRewardForTest(User $user, array $attributes = []): CashbackReward
{
    return CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create($attributes);
}

it('creates one verified account without retaining the full account number', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $accountNumber = '0000001234';

    $result = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput($accountNumber, '057'),
    );
    $payoutAccount = $result->payoutAccount;

    expect($result->wasCreated)->toBeTrue()
        ->and($payoutAccount->user_id)->toBe($user->id)
        ->and($payoutAccount->provider)->toBe(PaymentProvider::Fake)
        ->and($payoutAccount->provider_recipient_code)->not->toContain($accountNumber)
        ->and($payoutAccount->bank_code)->toBe('057')
        ->and($payoutAccount->bank_name)->toBe('Demo Bank')
        ->and($payoutAccount->account_name)->toBe('Demo Customer')
        ->and($payoutAccount->account_last_four)->toBe('1234')
        ->and($payoutAccount->currency)->toBe(Currency::Ngn)
        ->and($payoutAccount->verified_at)->not->toBeNull()
        ->and(Schema::getColumnListing('payout_accounts'))->not->toContain('account_number')
        ->and(json_encode($payoutAccount->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($accountNumber)
        ->and($payoutAccount->toArray())->not->toHaveKeys([
            'user_id',
            'provider_recipient_code',
            'account_last_four',
        ]);

    Event::assertDispatched(PayoutAccountVerified::class, function (PayoutAccountVerified $event) use ($payoutAccount): bool {
        expect(array_keys(get_object_vars($event)))->toBe(['payoutAccount'])
            ->and($event->payoutAccount->is($payoutAccount))->toBeTrue();

        return true;
    });
});

it('makes every clean waiting reward ready in the account save transaction', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $rewards = collect(range(1, 3))->map(
        static fn (): CashbackReward => payoutAccountRewardForTest($user),
    );

    app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    );

    expect($rewards->map(
        static fn (CashbackReward $reward): CashbackRewardStatus => $reward->refresh()->status,
    )->all())->toBe([
        CashbackRewardStatus::ReadyForPayout,
        CashbackRewardStatus::ReadyForPayout,
        CashbackRewardStatus::ReadyForPayout,
    ]);
    Event::assertDispatchedTimes(PayoutAccountVerified::class, 1);
});

it('changes only clean waiting rewards when an account is replaced', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $account = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    $cleanWaiting = payoutAccountRewardForTest($user);
    $providerBoundWaiting = payoutAccountRewardForTest($user, [
        'provider' => PaymentProvider::Fake,
    ]);
    $waitingWithPayout = payoutAccountRewardForTest($user);
    Payout::factory()->create([
        'cashback_reward_id' => $waitingWithPayout->id,
        'payout_account_id' => $account->id,
    ]);
    $unchangedStatuses = [
        CashbackRewardStatus::ReadyForPayout,
        CashbackRewardStatus::Processing,
        CashbackRewardStatus::Pending,
        CashbackRewardStatus::AwaitingFunds,
        CashbackRewardStatus::Retrying,
        CashbackRewardStatus::Paid,
        CashbackRewardStatus::RequiresAttention,
    ];
    $unchanged = collect($unchangedStatuses)->map(
        static fn (CashbackRewardStatus $status): CashbackReward => payoutAccountRewardForTest(
            $user,
            ['status' => $status],
        ),
    );

    app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000009876', '058'),
    );

    expect($cleanWaiting->refresh()->status)->toBe(CashbackRewardStatus::ReadyForPayout)
        ->and($providerBoundWaiting->refresh()->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($waitingWithPayout->refresh()->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($unchanged->map(
            static fn (CashbackReward $reward): CashbackRewardStatus => $reward->refresh()->status,
        )->all())->toBe($unchangedStatuses);
    Event::assertDispatchedTimes(PayoutAccountVerified::class, 2);
});

it('rolls back the account and readiness changes together on database failure', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $reward = payoutAccountRewardForTest($user);
    $originalUpdatedAt = $reward->updated_at;

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION fail_cashback_reward_readiness_update()
        RETURNS trigger AS $$
        BEGIN
            IF OLD.status = 'awaiting_payout_account' AND NEW.status = 'ready_for_payout' THEN
                RAISE EXCEPTION 'simulated readiness update failure';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
        SQL);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_cashback_reward_readiness_update
        BEFORE UPDATE ON cashback_rewards
        FOR EACH ROW EXECUTE FUNCTION fail_cashback_reward_readiness_update()
        SQL);

    try {
        expect(fn () => app(RegisterPayoutAccount::class)->handle(
            $user,
            new RegisterPayoutAccountInput('0000001234', '057'),
        ))->toThrow(QueryException::class, 'simulated readiness update failure');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_cashback_reward_readiness_update ON cashback_rewards');
        DB::unprepared('DROP FUNCTION IF EXISTS fail_cashback_reward_readiness_update()');
    }

    expect(PayoutAccount::query()->whereBelongsTo($user)->exists())->toBeFalse()
        ->and($reward->refresh()->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount)
        ->and($reward->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
    Event::assertNotDispatched(PayoutAccountVerified::class);
});

it('replaces the existing account in place and reports the outcome explicitly', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $action = app(RegisterPayoutAccount::class);

    $created = $action->handle($user, new RegisterPayoutAccountInput('0000001234', '057'));
    $replaced = $action->handle($user, new RegisterPayoutAccountInput('0000009876', '058'));

    expect($created->wasCreated)->toBeTrue()
        ->and($replaced->wasCreated)->toBeFalse()
        ->and($replaced->payoutAccount->id)->toBe($created->payoutAccount->id)
        ->and($replaced->payoutAccount->bank_code)->toBe('058')
        ->and($replaced->payoutAccount->account_last_four)->toBe('9876')
        ->and(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1);

    Event::assertDispatchedTimes(PayoutAccountVerified::class, 2);
});

it('logs created and replaced accounts after database commit and cache lock release', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    $action = app(RegisterPayoutAccount::class);
    $loggedContexts = [];
    $transactionLevelsWhenLogged = [];
    $couldAcquireLockWhenLogged = [];

    Log::shouldReceive('info')->twice()->with(
        'payout_account.saved',
        Mockery::on(function (array $context) use ($user, &$loggedContexts, &$transactionLevelsWhenLogged, &$couldAcquireLockWhenLogged): bool {
            $loggedContexts[] = $context;
            $transactionLevelsWhenLogged[] = DB::connection()->transactionLevel();
            $sameUserLock = Cache::lock("payout-account:user:{$user->id}", 1);
            $lockWasAcquired = $sameUserLock->get();
            $couldAcquireLockWhenLogged[] = $lockWasAcquired;

            if ($lockWasAcquired) {
                $sameUserLock->release();
            }

            return true;
        }),
    );

    $created = $action->handle($user, new RegisterPayoutAccountInput('0000001234', '057'));
    $replaced = $action->handle($user, new RegisterPayoutAccountInput('0000009876', '058'));

    expect($loggedContexts)->toBe([
        [
            'user_id' => $user->id,
            'payout_account_id' => $created->payoutAccount->id,
            'provider' => PaymentProvider::Fake->value,
            'result' => 'created',
        ],
        [
            'user_id' => $user->id,
            'payout_account_id' => $created->payoutAccount->id,
            'provider' => PaymentProvider::Fake->value,
            'result' => 'replaced',
        ],
    ])->and(array_keys($loggedContexts[0]))->toBe([
        'user_id',
        'payout_account_id',
        'provider',
        'result',
    ])->and($transactionLevelsWhenLogged)->toBe([0, 0])
        ->and($couldAcquireLockWhenLogged)->toBe([true, true])
        ->and(json_encode($loggedContexts, JSON_THROW_ON_ERROR))->not->toContain('0000001234')
        ->and(json_encode($loggedContexts, JSON_THROW_ON_ERROR))->not->toContain('0000009876');

    expect($replaced->payoutAccount->id)->toBe($created->payoutAccount->id);
});

it('keeps the payout account when account logging fails', function (): void {
    Event::fake([PayoutAccountVerified::class]);
    $user = User::factory()->create();
    Log::spy();
    Log::shouldReceive('info')
        ->once()
        ->with('payout_account.saved', Mockery::type('array'))
        ->andThrow(new RuntimeException('payout account log unavailable'));

    $registration = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    );

    expect($registration->wasCreated)->toBeTrue()
        ->and(PayoutAccount::query()->whereKey($registration->payoutAccount->id)->exists())->toBeTrue();
    Event::assertDispatchedTimes(PayoutAccountVerified::class, 1);
});

it('notifies listeners only after the local transaction commits', function (): void {
    $transactionLevels = [];
    Event::listen(PayoutAccountVerified::class, function () use (&$transactionLevels): void {
        $transactionLevels[] = DB::connection()->transactionLevel();
    });

    app(RegisterPayoutAccount::class)->handle(
        User::factory()->create(),
        new RegisterPayoutAccountInput('0000001234', '057'),
    );

    expect($transactionLevels)->toBe([0])
        ->and(PayoutAccount::query()->count())->toBe(1);
});

it('preserves the previous account and emits no event when the provider rejects replacement', function (): void {
    $user = User::factory()->create();
    $original = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    $originalDestination = [
        $original->id,
        $original->provider->value,
        $original->provider_recipient_code,
        $original->bank_code,
        $original->bank_name,
        $original->account_name,
        $original->account_last_four,
        $original->currency->value,
    ];
    Event::fake([PayoutAccountVerified::class]);
    Log::spy();
    config()->set('payments.fake.payout_account_scenario', 'rejected');

    try {
        app(RegisterPayoutAccount::class)->handle(
            $user,
            new RegisterPayoutAccountInput('0000009876', '058'),
        );
        test()->fail('The rejected replacement should throw.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::RecipientRejected);
    }

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect([
        $preserved->id,
        $preserved->provider->value,
        $preserved->provider_recipient_code,
        $preserved->bank_code,
        $preserved->bank_name,
        $preserved->account_name,
        $preserved->account_last_four,
        $preserved->currency->value,
    ])->toBe($originalDestination);
    Event::assertNotDispatched(PayoutAccountVerified::class);
    Log::shouldNotHaveReceived('info', [
        'payout_account.saved',
        Mockery::type('array'),
    ]);
});

it('maps a recipient identity conflict while preserving the previous account and suppressing its event', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $action = app(RegisterPayoutAccount::class);
    $original = $action->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    $originalDestination = [
        $original->id,
        $original->provider->value,
        $original->provider_recipient_code,
        $original->bank_code,
        $original->bank_name,
        $original->account_name,
        $original->account_last_four,
        $original->currency->value,
    ];
    $conflictingInput = new RegisterPayoutAccountInput('0000009876', '058');
    $conflictingRecipient = app(FakeTransferRecipientGateway::class)
        ->createRecipient($conflictingInput);

    PayoutAccount::factory()->for($otherUser)->create([
        'provider' => $conflictingRecipient->provider,
        'provider_recipient_code' => $conflictingRecipient->recipientCode,
    ]);
    Event::fake([PayoutAccountVerified::class]);
    Log::spy();

    expect(fn () => $action->handle($user, $conflictingInput))
        ->toThrow(PayoutAccountConflictException::class);

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect([
        $preserved->id,
        $preserved->provider->value,
        $preserved->provider_recipient_code,
        $preserved->bank_code,
        $preserved->bank_name,
        $preserved->account_name,
        $preserved->account_last_four,
        $preserved->currency->value,
    ])->toBe($originalDestination);
    Event::assertNotDispatched(PayoutAccountVerified::class);
    Log::shouldNotHaveReceived('info', [
        'payout_account.saved',
        Mockery::type('array'),
    ]);
});

it('does not reinterpret or replace a stored account when the configured provider is unavailable', function (): void {
    $user = User::factory()->create();
    $original = app(RegisterPayoutAccount::class)->handle(
        $user,
        new RegisterPayoutAccountInput('0000001234', '057'),
    )->payoutAccount;
    config()->set('payments.default', PaymentProvider::Paystack->value);
    Event::fake([PayoutAccountVerified::class]);

    try {
        app(RegisterPayoutAccount::class)->handle(
            $user,
            new RegisterPayoutAccountInput('0000009876', '058'),
        );
        test()->fail('Paystack without a test credential should fail safely.');
    } catch (PaymentProviderException $exception) {
        expect($exception->failure)->toBe(PaymentProviderFailure::Unavailable);
    }

    $preserved = PayoutAccount::query()->findOrFail($original->id);

    expect($preserved->provider)->toBe(PaymentProvider::Fake)
        ->and($preserved->account_last_four)->toBe('1234')
        ->and(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1);
    Event::assertNotDispatched(PayoutAccountVerified::class);
});

it('enforces payout account invariants in postgres', function (array $invalid): void {
    $payoutAccount = PayoutAccount::factory()->create();

    expect(fn () => DB::table('payout_accounts')->where('id', $payoutAccount->id)->update($invalid))
        ->toThrow(QueryException::class);
})->with([
    'unknown provider' => [['provider' => 'unknown']],
    'invalid bank code' => [['bank_code' => '57']],
    'invalid last four' => [['account_last_four' => '12x4']],
    'unsupported currency' => [['currency' => 'USD']],
]);

it('enforces one account per user and provider-scoped recipient identity', function (): void {
    $payoutAccount = PayoutAccount::factory()->create();

    expect(fn () => PayoutAccount::factory()->create(['user_id' => $payoutAccount->user_id]))
        ->toThrow(QueryException::class)
        ->and(fn () => PayoutAccount::factory()->create([
            'provider' => $payoutAccount->provider,
            'provider_recipient_code' => $payoutAccount->provider_recipient_code,
        ]))->toThrow(QueryException::class);

    $otherProvider = PayoutAccount::factory()->create([
        'provider' => PaymentProvider::Paystack,
        'provider_recipient_code' => $payoutAccount->provider_recipient_code,
    ]);

    expect($otherProvider->provider)->toBe(PaymentProvider::Paystack);
});

it('preserves the payout destination by restricting deletion of its customer', function (): void {
    $payoutAccount = PayoutAccount::factory()->create();
    $user = $payoutAccount->user()->firstOrFail();

    expect(fn () => $user->delete())->toThrow(QueryException::class)
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(PayoutAccount::query()->whereKey($payoutAccount->id)->exists())->toBeTrue();
});

it('rejects system identities and caller-owned transactions before provider work', function (): void {
    $system = User::factory()->system()->create();

    expect(fn () => app(RegisterPayoutAccount::class)->handle(
        $system,
        new RegisterPayoutAccountInput('0000001234', '057'),
    ))->toThrow(AuthorizationException::class);

    $customer = User::factory()->create();

    expect(fn () => DB::transaction(
        fn () => app(RegisterPayoutAccount::class)->handle(
            $customer,
            new RegisterPayoutAccountInput('0000001234', '057'),
        ),
    ))->toThrow(
        LogicException::class,
        'Payout account registration cannot run inside an existing database transaction.',
    );
});
