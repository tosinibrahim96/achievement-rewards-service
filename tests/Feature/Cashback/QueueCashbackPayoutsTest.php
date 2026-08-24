<?php

declare(strict_types=1);

use App\Actions\Cashback\QueueCashbackPayout;
use App\Actions\Cashback\QueueCashbackPayouts;
use App\Enums\CashbackRewardStatus;
use App\Enums\PayoutStatus;
use App\Events\BadgeUnlocked;
use App\Events\PayoutAccountVerified;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Jobs\ProcessCashbackPayoutJob;
use App\Listeners\QueueCashbackPayoutsOnBadgeUnlocked;
use App\Listeners\QueueCashbackPayoutsOnPayoutAccountVerified;
use App\Models\CashbackReward;
use App\Models\Payout;
use App\Models\PayoutAccount;
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
    return Queue::pushed(ProcessCashbackPayoutJob::class)
        ->map(
            static fn (ProcessCashbackPayoutJob $job): int => $job->cashbackRewardId,
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
    Queue::fake([ProcessCashbackPayoutJob::class]);
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

    Queue::assertPushed(ProcessCashbackPayoutJob::class, 3);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);
});

it('uses payout account verification to wake every ready reward for its user', function (): void {
    Queue::fake([ProcessCashbackPayoutJob::class]);
    $user = User::factory()->create();
    $expectedIds = collect([
        createReadyWakeUpReward($user)->id,
        createReadyWakeUpReward($user)->id,
    ])->sort()->values()->all();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();

    PayoutAccountVerified::dispatch($payoutAccount);

    Queue::assertPushed(ProcessCashbackPayoutJob::class, 2);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);
});

it('does not use an account event to repair a contradictory awaiting reward', function (): void {
    Queue::fake([ProcessCashbackPayoutJob::class]);
    $user = User::factory()->create();
    $reward = CashbackReward::factory()
        ->for($user)
        ->for(UserBadge::factory()->for($user), 'userBadge')
        ->create();

    BadgeUnlocked::dispatch('Beginner', $user);

    Queue::assertNotPushed(ProcessCashbackPayoutJob::class);

    $payoutAccount = PayoutAccount::factory()->for($user)->create();
    PayoutAccountVerified::dispatch($payoutAccount);

    Queue::assertNotPushed(ProcessCashbackPayoutJob::class);
    expect($reward->refresh()->status)->toBe(CashbackRewardStatus::AwaitingPayoutAccount);
});

it('does not dispatch payout work from a badge event before its transaction commits', function (): void {
    Queue::fake([ProcessCashbackPayoutJob::class]);
    $user = User::factory()->create();
    PayoutAccount::factory()->for($user)->create();

    DB::beginTransaction();

    try {
        $reward = createReadyWakeUpReward($user);
        BadgeUnlocked::dispatch('Beginner', $user);

        Queue::assertNotPushed(ProcessCashbackPayoutJob::class);

        DB::commit();
    } catch (Throwable $exception) {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $exception;
    }

    Queue::assertPushed(
        ProcessCashbackPayoutJob::class,
        static fn (ProcessCashbackPayoutJob $job): bool => $job->cashbackRewardId === $reward->id,
    );
});

it('runs the assembled queued wake-up and fake payout pipeline with real redis locks', function (): void {
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
    $job = new ProcessCashbackPayoutJob($reward->id);
    $uniqueLock = new UniqueLock(Cache::store('redis'));
    $effects->forget($reward->provider_reference);
    $uniqueLock->release($job);

    try {
        BadgeUnlocked::dispatch('Beginner', $user);

        $reward->refresh();
        $payout = Payout::query()->where('cashback_reward_id', $reward->id)->sole();

        expect($reward->status)->toBe(CashbackRewardStatus::Paid)
            ->and($payout->status)->toBe(PayoutStatus::Succeeded)
            ->and($effects->findByReference($reward->provider_reference)?->status)
            ->toBe(PayoutStatus::Succeeded)
            ->and($uniqueLock->acquire($job))->toBeTrue();
    } finally {
        $uniqueLock->release($job);
        $effects->forget($reward->provider_reference);
    }
});

it('queues only ready rewards without a payout when they have a verified account', function (): void {
    Queue::fake([ProcessCashbackPayoutJob::class]);
    $user = User::factory()->create();
    $payoutAccount = PayoutAccount::factory()->for($user)->create();
    $eligible = createReadyWakeUpReward($user);

    createReadyWakeUpReward($user, [
        'status' => CashbackRewardStatus::AwaitingPayoutAccount,
    ]);

    $paidOut = createReadyWakeUpReward($user);
    Payout::factory()->create([
        'cashback_reward_id' => $paidOut->id,
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
    Queue::fake([ProcessCashbackPayoutJob::class]);
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

    expect(Queue::pushed(ProcessCashbackPayoutJob::class)->every(
        static fn (ProcessCashbackPayoutJob $job): bool => collect(get_object_vars($job))
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

    expect(Queue::pushed(ProcessCashbackPayoutJob::class)->every(
        static fn (ProcessCashbackPayoutJob $job): bool => array_values(array_diff(
            array_keys(get_object_vars($job)),
            $frameworkAndExecutionFields,
        )) === ['cashbackRewardId']
            && is_int($job->cashbackRewardId),
    ))->toBeTrue();
});

it('configures reward-keyed queue uniqueness and a bounded execution overlap lease', function (): void {
    $job = new ProcessCashbackPayoutJob(42);
    $middleware = $job->middleware()[0];
    $uniqueLock = new UniqueLock(Cache::store('array'));

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->cashbackRewardId)->toBe(42)
        ->and($job->uniqueId())->toBe('42')
        ->and($uniqueLock->getKey($job))
        ->toBe('laravel_unique_job:App\\Jobs\\ProcessCashbackPayoutJob:42')
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->timeout)->toBe(30)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->releaseAfter)->toBeNull()
        ->and($middleware->expiresAfter)->toBe(60)
        ->and($middleware->getLockKey(new stdClass))->toBe('cashback-payout:reward:42');
});

it('releases the unique lock and rethrows when the queue push fails', function (): void {
    $cache = Cache::store('array');
    $failingBus = Mockery::mock(Dispatcher::class);
    $failingBus->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('The queue transport is unavailable.'));
    $cashbackRewardId = 501;

    expect(fn () => (new QueueCashbackPayout($cache, $failingBus))
        ->handle($cashbackRewardId))->toThrow(
            RuntimeException::class,
            'The queue transport is unavailable.',
        );

    $recoveredBus = Mockery::mock(Dispatcher::class);
    $recoveredBus->shouldReceive('dispatch')->once()->andReturn('queued');

    expect((new QueueCashbackPayout($cache, $recoveredBus))
        ->handle($cashbackRewardId))->toBeTrue();

    (new UniqueLock($cache))->release(new ProcessCashbackPayoutJob($cashbackRewardId));
});

it('rejects an invalid unbounded chunk size', function (int $chunkSize): void {
    expect(fn () => app(QueueCashbackPayouts::class)
        ->queueForAllUsers(chunkSize: $chunkSize))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('reports only newly queued jobs and remains safe to rerun', function (): void {
    Queue::fake([ProcessCashbackPayoutJob::class]);
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

    Queue::assertPushed(ProcessCashbackPayoutJob::class, 2);
    expect(queuedCashbackRewardIds())->toBe($expectedIds);

    Artisan::call('list', ['--raw' => true]);

    expect(Artisan::output())
        ->toContain('cashback:queue-payouts')
        ->not->toContain('cashback:dispatch-actionable');
});
