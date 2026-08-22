<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Events\AchievementUnlocked;
use App\Listeners\EvaluateBadgesListener;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
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
