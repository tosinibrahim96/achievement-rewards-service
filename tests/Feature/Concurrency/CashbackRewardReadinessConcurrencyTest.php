<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Actions\Cashback\ProcessCashbackPayment;
use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Payouts\RegisterPayoutAccountInput;
use App\Enums\CashbackRewardStatus;
use App\Enums\PaymentProvider;
use App\Enums\PayoutAttemptStatus;
use App\Events\BadgeUnlocked;
use App\Events\PayoutAccountVerified;
use App\Infrastructure\Payments\FakeTransferEffectRegistry;
use App\Models\CashbackReward;
use App\Models\PayoutAccount;
use App\Models\PayoutAttempt;
use App\Models\User;
use App\Models\UserBadge;
use Closure;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BadgeTestData;
use Tests\Support\ConcurrentRunner;

uses(DatabaseMigrations::class);

/** @return list<string> */
function readinessRaceKeys(string $namespace): array
{
    return [
        "{$namespace}:holder_locked",
        "{$namespace}:contender_pid",
        "{$namespace}:contender_wait",
    ];
}

function waitForReadinessRaceSignal(string $key): string
{
    $deadline = microtime(true) + 5;
    $redis = Redis::connection('default');

    while (microtime(true) < $deadline) {
        $value = $redis->command('get', [$key]);

        if (is_string($value)) {
            return $value;
        }

        usleep(10_000);
    }

    throw new RuntimeException("The readiness race signal {$key} was not observed.");
}

function runReadinessLockHolder(Closure $action, string $namespace): void
{
    $paused = false;

    DB::listen(function (QueryExecuted $query) use (&$paused, $namespace): void {
        if ($paused
            || ! str_contains($query->sql, 'from "users"')
            || ! str_contains($query->sql, 'for update')) {
            return;
        }

        $paused = true;
        $redis = Redis::connection('default');
        $redis->command('set', ["{$namespace}:holder_locked", '1']);
        $contenderPid = (int) waitForReadinessRaceSignal("{$namespace}:contender_pid");
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $activity = DB::selectOne(
                'SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE pid = ?',
                [$contenderPid],
            );

            if (($activity?->wait_event_type ?? null) === 'Lock') {
                $waitEvent = $activity?->wait_event;
                $redis->command('set', [
                    "{$namespace}:contender_wait",
                    is_string($waitEvent) ? $waitEvent : 'Lock',
                ]);

                return;
            }

            usleep(10_000);
        }

        throw new RuntimeException('The contender never waited on the customer row lock.');
    });

    $action();

    if (! $paused) {
        throw new RuntimeException('The holder did not execute the customer FOR UPDATE query.');
    }
}

function runReadinessLockContender(Closure $action, string $namespace): void
{
    $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
    $pid = $backend?->pid ?? null;

    if (! is_int($pid)) {
        throw new RuntimeException('The contender PostgreSQL backend ID was not available.');
    }

    Redis::connection('default')->command('set', ["{$namespace}:contender_pid", (string) $pid]);
    waitForReadinessRaceSignal("{$namespace}:holder_locked");
    $action();
}

beforeEach(function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
    config()->set('app.key', 'base64:cashback-readiness-concurrency-key');
    config()->set('cache.default', 'redis');
    config()->set('payments.default', PaymentProvider::Fake->value);
    config()->set('payments.fake.payout_account_scenario', 'success');
    config()->set('payments.fake.transfer_scenario', 'success');
    config()->set(
        'payments.fake.transfer_effect_namespace',
        'pest-readiness-'.Str::lower((string) Str::ulid()),
    );
});

it('converges on one ready reward when either transaction holds the customer lock first', function (
    string $firstWriter,
): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    $namespace = 'cashback-readiness-race:'.Str::lower((string) Str::ulid());
    $redis = Redis::connection('default');
    $raceKeys = readinessRaceKeys($namespace);
    $redis->command('del', $raceKeys);
    $badgeAction = static function () use ($user): void {
        Event::fake([BadgeUnlocked::class, PayoutAccountVerified::class]);
        app(EvaluateBadges::class)->handle(User::query()->findOrFail($user->id));
    };
    $accountAction = static function () use ($user): void {
        Event::fake([BadgeUnlocked::class, PayoutAccountVerified::class]);
        app(RegisterPayoutAccount::class)->handle(
            User::query()->findOrFail($user->id),
            new RegisterPayoutAccountInput('0000001234', '057'),
        );
    };
    $holderAction = $firstWriter === 'badge' ? $badgeAction : $accountAction;
    $contenderAction = $firstWriter === 'badge' ? $accountAction : $badgeAction;

    try {
        (new ConcurrentRunner)->run([
            static fn () => runReadinessLockHolder($holderAction, $namespace),
            static fn () => runReadinessLockContender($contenderAction, $namespace),
        ]);

        $waitEvent = $redis->command('get', ["{$namespace}:contender_wait"]);
        $isolation = DB::selectOne('SHOW transaction_isolation');
        $reward = CashbackReward::query()->whereBelongsTo($user)->sole();

        expect($isolation?->transaction_isolation)->toBe('read committed')
            ->and($redis->command('get', ["{$namespace}:holder_locked"]))->toBe('1')
            ->and($redis->command('get', ["{$namespace}:contender_pid"]))->toBeString()
            ->and($waitEvent)->toBeString()
            ->and(UserBadge::query()->whereBelongsTo($user)->count())->toBe(1)
            ->and(CashbackReward::query()->whereBelongsTo($user)->count())->toBe(1)
            ->and(PayoutAccount::query()->whereBelongsTo($user)->count())->toBe(1)
            ->and($reward->status)->toBe(CashbackRewardStatus::ReadyForPayout)
            ->and($reward->provider)->toBeNull()
            ->and(PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->count())
            ->toBe(0);

        $effects = app(FakeTransferEffectRegistry::class);
        $effects->forget($reward->provider_reference);
        $firstAttempt = app(ProcessCashbackPayment::class)->handle($reward->id);
        $duplicateAttempt = app(ProcessCashbackPayment::class)->handle($reward->id);

        expect($firstAttempt?->status)->toBe(PayoutAttemptStatus::Succeeded)
            ->and($duplicateAttempt)->toBeNull()
            ->and(PayoutAttempt::query()->where('cashback_reward_id', $reward->id)->count())
            ->toBe(1)
            ->and($effects->findByReference($reward->provider_reference)?->status)
            ->toBe(PayoutAttemptStatus::Succeeded);
    } finally {
        if (isset($reward) && $reward instanceof CashbackReward) {
            app(FakeTransferEffectRegistry::class)->forget($reward->provider_reference);
        }

        $redis->command('del', $raceKeys);
    }
})->with([
    'badge transaction first' => ['badge'],
    'account transaction first' => ['account'],
]);
