<?php

declare(strict_types=1);

use App\Actions\Cashback\DispatchActionableCashbackRewards;
use App\Actions\Cashback\EnqueueCashbackPayment;
use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Events\BadgeUnlocked;
use App\Events\PayoutAccountVerified;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Jobs\ProcessCashbackPaymentJob;
use App\Listeners\DispatchCashbackRewardsOnBadgeUnlocked;
use App\Listeners\DispatchCashbackRewardsOnPayoutAccountVerified;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

uses(DatabaseMigrations::class);

function createActionableWakeUpReward(User $user, array $attributes = []): CashbackReward
{
    $userBadge = UserBadge::factory()->for($user)->create();

    return CashbackReward::factory()
        ->for($user)
        ->for($userBadge, 'userBadge')
        ->readyForPayout()
        ->create($attributes);
}

/** @return list<int> */
function queuedCashbackRewardIds(): array
{
    return Queue::pushed(ProcessCashbackPaymentJob::class)
        ->map(
            static fn (ProcessCashbackPaymentJob $job): int => $job->cashbackRewardId,
        )
        ->values()
        ->all();
}

it('autodiscovers both thin queued wake-up listeners', function (): void {
    Event::fake();

    Event::assertListening(
        BadgeUnlocked::class,
        DispatchCashbackRewardsOnBadgeUnlocked::class,
    );
    Event::assertListening(
        PayoutAccountVerified::class,
        DispatchCashbackRewardsOnPayoutAccountVerified::class,
    );

    expect(app(DispatchCashbackRewardsOnBadgeUnlocked::class))
        ->toBeInstanceOf(ShouldQueue::class)
        ->tries->toBe(10)
        ->and(app(DispatchCashbackRewardsOnPayoutAccountVerified::class))
        ->toBeInstanceOf(ShouldQueue::class)
        ->tries->toBe(10);

    Artisan::call('event:list');

    expect(Artisan::output())
        ->toContain(BadgeUnlocked::class)
        ->toContain(DispatchCashbackRewardsOnBadgeUnlocked::class)
        ->toContain(PayoutAccountVerified::class)
        ->toContain(DispatchCashbackRewardsOnPayoutAccountVerified::class);
});

it('treats duplicate badge events as user wake-ups and repairs every missed reward', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    PayoutAccount::factory()->for($otherUser)->create();
    $expectedIds = collect([
        createActionableWakeUpReward($user)->id,
        createActionableWakeUpReward($user)->id,
        createActionableWakeUpReward($user)->id,
    ])->sort()->values()->all();
    createActionableWakeUpReward($otherUser);

    BadgeUnlocked::dispatch('A display name that identifies no reward', $user);
    BadgeUnlocked::dispatch('A different duplicate signal', $user);

    Queue::assertPushed(ProcessCashbackPaymentJob::class, 3);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);
});

it('uses payout account verification to wake every ready reward for its user', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $expectedIds = collect([
        createActionableWakeUpReward($user)->id,
        createActionableWakeUpReward($user)->id,
    ])->sort()->values()->all();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();

    PayoutAccountVerified::dispatch($payoutAccount);

    Queue::assertPushed(ProcessCashbackPaymentJob::class, 2);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);
});

it('does not use an account event to repair a contradictory awaiting reward', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();

    BadgeUnlocked::dispatch('Beginner', $user);

    Queue::assertNotPushed(ProcessCashbackPaymentJob::class);

    $payoutAccount = PayoutAccount::factory()->for($user)->create();
    PayoutAccountVerified::dispatch($payoutAccount);

    Queue::assertNotPushed(ProcessCashbackPaymentJob::class);
    expect($reward->refresh()->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount);
});

it('does not dispatch payment work from a badge event before its transaction commits', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();

    DB::beginTransaction();

    try {
        $reward = createActionableWakeUpReward($user);
        BadgeUnlocked::dispatch('Beginner', $user);

        Queue::assertNotPushed(ProcessCashbackPaymentJob::class);

        DB::commit();
    } catch (Throwable $exception) {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $exception;
    }

    Queue::assertPushed(
        ProcessCashbackPaymentJob::class,
        static fn (ProcessCashbackPaymentJob $job): bool => $job->cashbackRewardId === $reward->id,
    );
});

it('runs the assembled queued wake-up and fake payment pipeline with real redis locks', function (): void {
    config()->set('cache.default', 'redis');
    config()->set('queue.default', 'sync');
    config()->set('payments.fake.transfer_scenario', 'success');
    config()->set(
        'payments.fake.transfer_effect_namespace',
        'pest-assembled-'.Str::lower((string) Str::ulid()),
    );
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    $reward = createActionableWakeUpReward($user);
    $effects = app(FakeTransferEffectRegistry::class);
    $job = new ProcessCashbackPaymentJob($reward->id);
    $uniqueLock = new UniqueLock(Cache::store('redis'));
    $effects->forget($reward->provider_reference);
    $uniqueLock->release($job);

    try {
        BadgeUnlocked::dispatch('Beginner', $user);

        $reward->refresh();
        $attempt = PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->sole();

        expect($reward->status)->toBe(CashbackRewardStatus::Paid)
            ->and($attempt->status)->toBe(PayoutAttemptStatus::Succeeded)
            ->and($effects->findByReference($reward->provider_reference)?->status)
            ->toBe(PayoutAttemptStatus::Succeeded)
            ->and($uniqueLock->acquire($job))->toBeTrue();
    } finally {
        $uniqueLock->release($job);
        $effects->forget($reward->provider_reference);
    }
});

it('dispatches only unbound unattempted ready rewards with a verified account', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();
    $eligible = createActionableWakeUpReward($user);

    createActionableWakeUpReward($user, [
        'status' => CashbackRewardStatus::AwaitingPayoutAccount,
    ]);

    createActionableWakeUpReward(
        $user,
        ['provider' => PaymentProvider::Fake],
    );

    $attempted = createActionableWakeUpReward($user);
    PayoutAttempt::factory()->create([
        'cashback_reward_id' => $attempted->id,
        'payout_account_id' => $payoutAccount->id,
    ]);

    foreach (array_filter(
        CashbackRewardStatus::cases(),
        static fn (CashbackRewardStatus $status): bool => $status !== CashbackRewardStatus::ReadyForPayout,
    ) as $status) {
        createActionableWakeUpReward($user, ['status' => $status]);
    }

    $userWithoutAccount = User::factory()->create();
    createActionableWakeUpReward($userWithoutAccount);

    $count = app(DispatchActionableCashbackRewards::class)
        ->dispatchForAllUsers(chunkSize: 2);

    expect($count)->toBe(1)
        ->and(queuedCashbackRewardIds())->toBe([$eligible->id]);
});

it('scans all users in deterministic bounded id chunks and keeps the job payload minimal', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    PayoutAccount::factory()->for($firstUser)->create();
    PayoutAccount::factory()->for($secondUser)->create();
    $expectedIds = [
        createActionableWakeUpReward($secondUser)->id,
        createActionableWakeUpReward($firstUser)->id,
        createActionableWakeUpReward($secondUser)->id,
        createActionableWakeUpReward($firstUser)->id,
        createActionableWakeUpReward($secondUser)->id,
    ];
    sort($expectedIds);

    $count = app(DispatchActionableCashbackRewards::class)
        ->dispatchForAllUsers(chunkSize: 2);

    expect($count)->toBe(5)
        ->and(queuedCashbackRewardIds())->toBe($expectedIds);

    expect(Queue::pushed(ProcessCashbackPaymentJob::class)->every(
        static fn (ProcessCashbackPaymentJob $job): bool => collect(get_object_vars($job))
            ->doesntContain(static fn (mixed $value): bool => $value instanceof Model),
    ))->toBeTrue();

    $frameworkAndExecutionFields = [
        'connection',
        'queue',
        'messageGroup',
        'deduplicator',
        'debounceOwner',
        'uniqueLockOwner',
        'delay',
        'afterCommit',
        'middleware',
        'chained',
        'chainConnection',
        'chainQueue',
        'chainCatchCallbacks',
        'job',
        'timeout',
        'uniqueFor',
    ];

    expect(Queue::pushed(ProcessCashbackPaymentJob::class)->every(
        static fn (ProcessCashbackPaymentJob $job): bool => array_values(array_diff(
            array_keys(get_object_vars($job)),
            $frameworkAndExecutionFields,
        )) === ['cashbackRewardId']
            && is_int($job->cashbackRewardId),
    ))->toBeTrue();
});

it('configures reward-keyed queue uniqueness and a bounded execution overlap lease', function (): void {
    $job = new ProcessCashbackPaymentJob(42);
    $middleware = $job->middleware()[0];

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->cashbackRewardId)->toBe(42)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->timeout)->toBe(30)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->releaseAfter)->toBeNull()
        ->and($middleware->expiresAfter)->toBe(60)
        ->and($middleware->getLockKey(new stdClass))->toBe('cashback-payment:reward:42');
});

it('releases the unique lock and rethrows when the queue push fails', function (): void {
    $cache = Cache::store('array');
    $failingBus = Mockery::mock(Dispatcher::class);
    $failingBus->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('The queue transport is unavailable.'));
    $cashbackRewardId = 501;

    expect(fn () => (new EnqueueCashbackPayment($cache, $failingBus))
        ->handle($cashbackRewardId))->toThrow(
            RuntimeException::class,
            'The queue transport is unavailable.',
        );

    $recoveredBus = Mockery::mock(Dispatcher::class);
    $recoveredBus->shouldReceive('dispatch')->once()->andReturn('queued');

    expect((new EnqueueCashbackPayment($cache, $recoveredBus))
        ->handle($cashbackRewardId))->toBeTrue();

    (new UniqueLock($cache))->release(new ProcessCashbackPaymentJob($cashbackRewardId));
});

it('rejects an invalid unbounded chunk size', function (int $chunkSize): void {
    expect(fn () => app(DispatchActionableCashbackRewards::class)
        ->dispatchForAllUsers(chunkSize: $chunkSize))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('reports the bounded activation count and remains safe to rerun', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    $expectedIds = [
        createActionableWakeUpReward($user)->id,
        createActionableWakeUpReward($user)->id,
    ];

    expect(Artisan::call('cashback:dispatch-actionable'))->toBe(0)
        ->and(Artisan::output())->toContain('Requested processing for 2 actionable cashback reward(s).');

    expect(Artisan::call('cashback:dispatch-actionable'))->toBe(0)
        ->and(Artisan::output())->toContain('Requested processing for 2 actionable cashback reward(s).');

    Queue::assertPushed(ProcessCashbackPaymentJob::class, 2);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);
});
