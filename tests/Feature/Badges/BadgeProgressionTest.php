<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Events\AchievementUnlocked;
use App\Events\BadgeUnlocked;
use App\Http\Middleware\AssignRequestId;
use App\Listeners\EvaluateBadgesListener;
use App\Models\Badge;
use App\Models\CashbackReward;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\BadgeTestData;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
});

it('awards every reached badge in rank order', function (int $achievementCount, array $expected): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, $achievementCount);

    app(EvaluateBadges::class)->handle($user);

    expect(BadgeTestData::codesFor($user))->toBe($expected);
})->with([
    'no achievements' => [0, []],
    'beginner boundary' => [1, ['beginner']],
    'intermediate boundary' => [4, ['beginner', 'intermediate']],
    'between intermediate and advanced' => [5, ['beginner', 'intermediate']],
    'advanced boundary' => [8, ['beginner', 'intermediate', 'advanced']],
    'master boundary' => [10, ['beginner', 'intermediate', 'advanced', 'master']],
]);

it('ignores inactive badges while awarding other reached thresholds', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 4);
    Badge::query()->where('code', 'beginner')->update(['is_active' => false]);

    app(EvaluateBadges::class)->handle($user);

    expect(BadgeTestData::codesFor($user))->toBe(['intermediate']);
});

it('keeps badge awards idempotent when evaluation is replayed', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 8);
    $evaluateBadges = app(EvaluateBadges::class);

    $evaluateBadges->handle($user);
    $initialIds = UserBadge::query()->whereBelongsTo($user)->orderBy('id')->pluck('id')->all();
    $evaluateBadges->handle($user);

    expect(UserBadge::query()->whereBelongsTo($user)->orderBy('id')->pluck('id')->all())
        ->toBe($initialIds);
});

it('logs new badges and reads their reward ids with one query', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 8);
    $latestAchievement = UserAchievement::query()
        ->whereBelongsTo($user)
        ->orderByDesc('unlocked_at')
        ->orderByDesc('id')
        ->firstOrFail();
    $requestId = 'request-badge-evaluation';
    $previousCorrelationId = 'previous-workflow';
    $loggedContext = [];
    $rewardSelects = [];
    Context::add(AssignRequestId::ATTRIBUTE, $requestId);
    Context::add('correlation_id', $previousCorrelationId);
    DB::listen(function (QueryExecuted $query) use (&$rewardSelects): void {
        $sql = strtolower($query->sql);

        if (str_starts_with(ltrim($sql), 'select')
            && str_contains($sql, 'from "cashback_rewards"')) {
            $rewardSelects[] = [
                'sql' => $sql,
                'bindings' => $query->bindings,
            ];
        }
    });

    Log::shouldReceive('info')->once()->with(
        'badge.evaluation.completed',
        Mockery::on(function (array $context) use ($requestId, $latestAchievement, &$loggedContext): bool {
            $loggedContext = $context;

            return Context::get(AssignRequestId::ATTRIBUTE) === $requestId
                && Context::get('correlation_id') === $latestAchievement->correlation_id;
        }),
    );

    app(EvaluateBadges::class)->handle($user);
    $rewardSelectsDuringBadgeCheck = $rewardSelects;
    $storedRewardIds = CashbackReward::query()
        ->whereBelongsTo($user)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect(array_keys($loggedContext))->toBe([
        'user_id',
        'trigger_user_achievement_id',
        'correlation_id',
        'achievement_count',
        'unlocked_badge_names',
        'cashback_reward_ids',
    ])->and($loggedContext)->toBe([
        'user_id' => $user->id,
        'trigger_user_achievement_id' => $latestAchievement->id,
        'correlation_id' => $latestAchievement->correlation_id,
        'achievement_count' => 8,
        'unlocked_badge_names' => ['Beginner', 'Intermediate', 'Advanced'],
        'cashback_reward_ids' => $storedRewardIds,
    ])->and($rewardSelectsDuringBadgeCheck)->toHaveCount(1)
        ->and($rewardSelectsDuringBadgeCheck[0]['sql'])->toContain('"user_badge_id" in')
        ->and($rewardSelectsDuringBadgeCheck[0]['bindings'])->toHaveCount(3)
        ->and(Context::get('correlation_id'))->toBe($previousCorrelationId);
});

it('logs no new badges when the user has no achievements', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    $previousCorrelationId = 'previous-workflow';
    $correlationDuringLog = 'not-observed';
    Context::add('correlation_id', $previousCorrelationId);
    Log::shouldReceive('info')->once()->with(
        'badge.evaluation.completed',
        Mockery::on(function (array $context) use ($user, &$correlationDuringLog): bool {
            $correlationDuringLog = Context::get('correlation_id');

            return $context === [
                'user_id' => $user->id,
                'trigger_user_achievement_id' => null,
                'correlation_id' => null,
                'achievement_count' => 0,
                'unlocked_badge_names' => [],
                'cashback_reward_ids' => [],
            ];
        }),
    );

    app(EvaluateBadges::class)->handle($user);

    expect($correlationDuringLog)->toBeNull()
        ->and(Context::get('correlation_id'))->toBe($previousCorrelationId);
});

it('logs no new badges when the badge check runs again', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 8);
    $latestAchievement = UserAchievement::query()
        ->whereBelongsTo($user)
        ->orderByDesc('unlocked_at')
        ->orderByDesc('id')
        ->firstOrFail();
    $evaluateBadges = app(EvaluateBadges::class);
    $evaluateBadges->handle($user);
    Log::spy();

    $evaluateBadges->handle($user);

    Log::shouldHaveReceived('info')->once()->with('badge.evaluation.completed', [
        'user_id' => $user->id,
        'trigger_user_achievement_id' => $latestAchievement->id,
        'correlation_id' => $latestAchievement->correlation_id,
        'achievement_count' => 8,
        'unlocked_badge_names' => [],
        'cashback_reward_ids' => [],
    ]);
    Event::assertDispatchedTimes(BadgeUnlocked::class, 3);
});

it('keeps badges and rewards when badge logging fails', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    Log::spy();
    Log::shouldReceive('info')
        ->once()
        ->with('badge.evaluation.completed', Mockery::type('array'))
        ->andThrow(new RuntimeException('badge log unavailable'));

    app(EvaluateBadges::class)->handle($user);

    expect(UserBadge::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CashbackReward::query()->whereBelongsTo($user)->count())->toBe(1);
    Event::assertDispatchedTimes(BadgeUnlocked::class, 1);
});

it('enforces one award per user and badge at the database boundary', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);
    app(EvaluateBadges::class)->handle($user);
    $userBadge = UserBadge::query()->whereBelongsTo($user)->firstOrFail();

    expect(fn () => UserBadge::query()->create([
        'user_id' => $userBadge->user_id,
        'badge_id' => $userBadge->badge_id,
        'triggered_by_user_achievement_id' => $userBadge->triggered_by_user_achievement_id,
        'correlation_id' => $userBadge->correlation_id,
        'unlocked_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('evaluates badges through the queued AchievementUnlocked listener', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);

    AchievementUnlocked::dispatch('First Purchase', $user);

    expect(BadgeTestData::codesFor($user))->toBe(['beginner']);
});

it('discovers a queued per-user badge listener with its own progression lock', function (): void {
    Event::fake();
    Event::assertListening(AchievementUnlocked::class, EvaluateBadgesListener::class);

    $user = User::factory()->create();
    $listener = app(EvaluateBadgesListener::class);
    $middleware = $listener->middleware(new AchievementUnlocked('First Purchase', $user))[0];

    expect($listener)->toBeInstanceOf(EvaluateBadgesListener::class)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->releaseAfter)->toBe(1)
        ->and($middleware->expiresAfter)->toBe(60)
        ->and($middleware->getLockKey(new stdClass))->toBe("badge-progression:user:{$user->id}");

    Artisan::call('event:list');

    expect(Artisan::output())
        ->toContain(AchievementUnlocked::class)
        ->toContain(EvaluateBadgesListener::class);
});
