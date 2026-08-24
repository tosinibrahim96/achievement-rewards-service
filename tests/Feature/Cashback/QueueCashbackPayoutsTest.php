<?php

declare(strict_types=1);

use App\Actions\Cashback\EnqueueCashbackPayment;
use App\Actions\Cashback\QueueCashbackPayouts;
use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Events\BadgeUnlocked;
use App\Events\PayoutAccountVerified;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Jobs\ProcessCashbackPaymentJob;
use App\Listeners\QueueCashbackPayoutsOnBadgeUnlocked;
use App\Listeners\QueueCashbackPayoutsOnPayoutAccountVerified;
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

function createReadyWakeUpReward(User $user, array $attributes = []): CashbackReward
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
        QueueCashbackPayoutsOnBadgeUnlocked::class,
    );
    Event::assertListening(
        PayoutAccountVerified::class,
        QueueCashbackPayoutsOnPayoutAccountVerified::class,
    );

    expect(app(QueueCashbackPayoutsOnBadgeUnlocked::class))
        ->toBeInstanceOf(ShouldQueue::class)
        ->tries->toBe(10)
        ->and(app(QueueCashbackPayoutsOnPayoutAccountVerified::class))
        ->toBeInstanceOf(ShouldQueue::class)
        ->tries->toBe(10);

    Artisan::call('event:list');

    expect(Artisan::output())
        ->toContain(BadgeUnlocked::class)
        ->toContain(QueueCashbackPayoutsOnBadgeUnlocked::class)
        ->toContain(PayoutAccountVerified::class)
        ->toContain(QueueCashbackPayoutsOnPayoutAccountVerified::class)
        ->not->toContain('DispatchCashbackRewardsOnBadgeUnlocked')
        ->not->toContain('DispatchCashbackRewardsOnPayoutAccountVerified');
});

it('treats duplicate badge events as user wake-ups and repairs every missed reward', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    PayoutAccount::factory()->for($otherUser)->create();
    $expectedIds = collect([
        createReadyWakeUpReward($user)->id,
        createReadyWakeUpReward($user)->id,
        createReadyWakeUpReward($user)->id,
    ])->sort()->values()->all();
    createReadyWakeUpReward($otherUser);

    BadgeUnlocked::dispatch('A display name that identifies no reward', $user);
    BadgeUnlocked::dispatch('A different duplicate signal', $user);

    Queue::assertPushed(ProcessCashbackPaymentJob::class, 3);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);
});

it('uses payout account verification to wake every ready reward for its user', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $expectedIds = collect([
        createReadyWakeUpReward($user)->id,
        createReadyWakeUpReward($user)->id,
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
        $reward = createReadyWakeUpReward($user);
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
    $reward = createReadyWakeUpReward($user);
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

it('queues only unbound unattempted ready rewards with a verified account', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();
    $eligible = createReadyWakeUpReward($user);

    createReadyWakeUpReward($user, [
        'status' => CashbackRewardStatus::AwaitingPayoutAccount,
    ]);

    createReadyWakeUpReward(
        $user,
        ['provider' => PaymentProvider::Fake],
    );

    $attempted = createReadyWakeUpReward($user);
    PayoutAttempt::factory()->create([
        'cashback_reward_id' => $attempted->id,
        'payout_account_id' => $payoutAccount->id,
    ]);

    foreach (array_filter(
        CashbackRewardStatus::cases(),
        static fn (CashbackRewardStatus $status): bool => $status !== CashbackRewardStatus::ReadyForPayout,
    ) as $status) {
        createReadyWakeUpReward($user, ['status' => $status]);
    }

    $userWithoutAccount = User::factory()->create();
    createReadyWakeUpReward($userWithoutAccount);

    $count = app(QueueCashbackPayouts::class)
        ->queueForAllUsers(chunkSize: 2);

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
        createReadyWakeUpReward($secondUser)->id,
        createReadyWakeUpReward($firstUser)->id,
        createReadyWakeUpReward($secondUser)->id,
        createReadyWakeUpReward($firstUser)->id,
        createReadyWakeUpReward($secondUser)->id,
    ];
    sort($expectedIds);

    $count = app(QueueCashbackPayouts::class)
        ->queueForAllUsers(chunkSize: 2);

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
    expect(fn () => app(QueueCashbackPayouts::class)
        ->queueForAllUsers(chunkSize: $chunkSize))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('reports only newly queued jobs and remains safe to rerun', function (): void {
    Queue::fake([ProcessCashbackPaymentJob::class]);
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();
    $expectedIds = [
        createReadyWakeUpReward($user)->id,
        createReadyWakeUpReward($user)->id,
    ];

    expect(Artisan::call('cashback:queue-payouts'))->toBe(0)
        ->and(Artisan::output())->toContain('Queued 2 cashback payout job(s).');

    expect(Artisan::call('cashback:queue-payouts'))->toBe(0)
        ->and(Artisan::output())->toContain('Queued 0 cashback payout job(s).');

    Queue::assertPushed(ProcessCashbackPaymentJob::class, 2);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);

    Artisan::call('list', ['--raw' => true]);

    expect(Artisan::output())
        ->toContain('cashback:queue-payouts')
        ->not->toContain('cashback:dispatch-actionable');
});
