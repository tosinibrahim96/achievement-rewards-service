<?php

declare(strict_types=1);

use App\Actions\Achievements\GetUserAchievementProgress;
use App\Data\Achievements\UserAchievementProgress;
use App\Enums\AchievementMetric;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserBadge;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BadgeTestData;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
});

function grantProgressAchievement(User $user, string $code): void
{
    $achievement = Achievement::query()->where('code', $code)->firstOrFail();

    UserAchievement::factory()
        ->for($user)
        ->for($achievement)
        ->create();
}

function grantProgressBadge(User $user, string $code): void
{
    $badge = Badge::query()->where('code', $code)->firstOrFail();

    UserBadge::factory()
        ->for($user)
        ->for($badge)
        ->create();
}

it('returns initial progress without writing to the database', function (): void {
    $user = User::factory()->create();
    $action = app(GetUserAchievementProgress::class);

    $progress = DB::transaction(static function () use ($action, $user): UserAchievementProgress {
        DB::statement('SET TRANSACTION READ ONLY');

        return $action->handle($user);
    });

    expect($progress->unlockedAchievements)->toBe([])
        ->and($progress->nextAvailableAchievements)->toBe([
            'First Purchase',
            'NGN 5,000 Spent',
        ])
        ->and($progress->currentBadge)->toBeNull()
        ->and($progress->nextBadge)->toBe('Beginner')
        ->and($progress->remainingToUnlockNextBadge)->toBe(1);
});

it('uses the configured order and omits completed groups', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $codesInDisplayOrder = [
        'first-purchase',
        'three-purchases',
        'five-purchases',
        'ten-purchases',
        'twenty-five-purchases',
        'five-thousand-spent',
        'ten-thousand-spent',
    ];

    foreach (array_reverse($codesInDisplayOrder) as $code) {
        grantProgressAchievement($user, $code);
    }

    grantProgressAchievement($otherUser, 'one-hundred-thousand-spent');
    grantProgressBadge($user, 'intermediate');
    grantProgressBadge($user, 'beginner');
    grantProgressBadge($otherUser, 'master');

    $progress = app(GetUserAchievementProgress::class)->handle($user);

    expect($progress->unlockedAchievements)->toBe([
        'First Purchase',
        '3 Purchases',
        '5 Purchases',
        '10 Purchases',
        '25 Purchases',
        'NGN 5,000 Spent',
        'NGN 10,000 Spent',
    ])
        ->and($progress->nextAvailableAchievements)->toBe(['NGN 25,000 Spent'])
        ->and($progress->currentBadge)->toBe('Intermediate')
        ->and($progress->nextBadge)->toBe('Advanced')
        ->and($progress->remainingToUnlockNextBadge)->toBe(1);
});

it('orders groups and achievements by code when sort orders match', function (): void {
    $user = User::factory()->create();
    $customGroup = AchievementGroup::factory()->create([
        'code' => 'alpha-progress',
        'name' => 'Alpha Progress',
        'metric' => AchievementMetric::PurchaseCount,
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $zuluAchievement = Achievement::factory()->for($customGroup, 'group')->create([
        'code' => 'zulu-earned',
        'name' => 'Zulu Earned',
        'threshold' => 3,
        'sort_order' => 1,
    ]);
    $betaAchievement = Achievement::factory()->for($customGroup, 'group')->create([
        'code' => 'beta-earned',
        'name' => 'Beta Earned',
        'threshold' => 2,
        'sort_order' => 1,
    ]);
    Achievement::factory()->for($customGroup, 'group')->create([
        'code' => 'alpha-next',
        'name' => 'Alpha Next',
        'threshold' => 1,
        'sort_order' => 1,
    ]);

    UserAchievement::factory()->for($user)->for($zuluAchievement)->create();
    UserAchievement::factory()->for($user)->for($betaAchievement)->create();

    $progress = app(GetUserAchievementProgress::class)->handle($user);

    expect($progress->unlockedAchievements)->toBe([
        'Beta Earned',
        'Zulu Earned',
    ])
        ->and($progress->nextAvailableAchievements)->toBe([
            'Alpha Next',
            'First Purchase',
            'NGN 5,000 Spent',
        ]);
});

it('keeps earned inactive items and skips inactive next items', function (): void {
    $user = User::factory()->create();
    grantProgressAchievement($user, 'first-purchase');
    grantProgressAchievement($user, 'five-thousand-spent');
    grantProgressBadge($user, 'beginner');

    Achievement::query()
        ->whereIn('code', ['first-purchase', 'three-purchases'])
        ->update(['is_active' => false]);
    AchievementGroup::query()->where('code', 'lifetime-spend')->update(['is_active' => false]);
    Badge::query()->whereIn('code', ['beginner', 'intermediate'])->update(['is_active' => false]);

    $progress = app(GetUserAchievementProgress::class)->handle($user);

    expect($progress->unlockedAchievements)->toBe([
        'First Purchase',
        'NGN 5,000 Spent',
    ])
        ->and($progress->nextAvailableAchievements)->toBe(['5 Purchases'])
        ->and($progress->currentBadge)->toBe('Beginner')
        ->and($progress->nextBadge)->toBe('Advanced')
        ->and($progress->remainingToUnlockNextBadge)->toBe(6);
});

it('handles delayed badge updates without guessing or saving badges', function (): void {
    $userWithoutBadge = User::factory()->create();
    BadgeTestData::giveAchievements($userWithoutBadge, 8);

    $beginnerUser = User::factory()->create();
    BadgeTestData::giveAchievements($beginnerUser, 8);
    grantProgressBadge($beginnerUser, 'beginner');

    $action = app(GetUserAchievementProgress::class);
    [$withoutBadgeProgress, $beginnerProgress] = DB::transaction(static function () use (
        $action,
        $userWithoutBadge,
        $beginnerUser,
    ): array {
        DB::statement('SET TRANSACTION READ ONLY');

        return [
            $action->handle($userWithoutBadge),
            $action->handle($beginnerUser),
        ];
    });

    expect($withoutBadgeProgress->currentBadge)->toBeNull()
        ->and($withoutBadgeProgress->nextBadge)->toBe('Beginner')
        ->and($withoutBadgeProgress->remainingToUnlockNextBadge)->toBe(0)
        ->and($beginnerProgress->currentBadge)->toBe('Beginner')
        ->and($beginnerProgress->nextBadge)->toBe('Intermediate')
        ->and($beginnerProgress->remainingToUnlockNextBadge)->toBe(0);
});

it('returns current and next badges at each boundary', function (
    int $achievementCount,
    string $currentBadgeCode,
    string $currentBadgeName,
    string $nextBadgeName,
    int $expectedRemaining,
): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, $achievementCount);
    grantProgressBadge($user, $currentBadgeCode);

    $progress = app(GetUserAchievementProgress::class)->handle($user);

    expect($progress->currentBadge)->toBe($currentBadgeName)
        ->and($progress->nextBadge)->toBe($nextBadgeName)
        ->and($progress->remainingToUnlockNextBadge)->toBe($expectedRemaining);
})->with([
    'Beginner at one achievement' => [1, 'beginner', 'Beginner', 'Intermediate', 3],
    'Intermediate at four achievements' => [4, 'intermediate', 'Intermediate', 'Advanced', 4],
    'Intermediate with five achievements' => [5, 'intermediate', 'Intermediate', 'Advanced', 3],
    'Advanced at eight achievements' => [8, 'advanced', 'Advanced', 'Master', 2],
]);

it('returns no next badge after Master', function (): void {
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 10);
    grantProgressBadge($user, 'master');

    $progress = app(GetUserAchievementProgress::class)->handle($user);

    expect($progress->unlockedAchievements)->toHaveCount(10)
        ->and($progress->nextAvailableAchievements)->toBe([])
        ->and($progress->currentBadge)->toBe('Master')
        ->and($progress->nextBadge)->toBeNull()
        ->and($progress->remainingToUnlockNextBadge)->toBe(0);
});

it('keeps the query count at four as data grows', function (): void {
    $user = User::factory()->create();
    $action = app(GetUserAchievementProgress::class);
    $queries = [];

    grantProgressBadge($user, 'beginner');
    grantProgressBadge($user, 'intermediate');
    grantProgressBadge($user, 'advanced');

    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $action->handle($user);
    $initialQueryCount = count($queries);

    foreach (range(1, 12) as $sequence) {
        $group = AchievementGroup::factory()->create([
            'code' => "extension-group-{$sequence}",
            'name' => "Extension Group {$sequence}",
            'sort_order' => 100 + $sequence,
        ]);
        $earned = Achievement::factory()->for($group, 'group')->create([
            'code' => "extension-earned-{$sequence}",
            'name' => "Extension Earned {$sequence}",
            'threshold' => 1,
            'sort_order' => 1,
        ]);
        Achievement::factory()->for($group, 'group')->create([
            'code' => "extension-next-{$sequence}",
            'name' => "Extension Next {$sequence}",
            'threshold' => 2,
            'sort_order' => 2,
        ]);
        UserAchievement::factory()->for($user)->for($earned)->create();
    }

    foreach (range(1, 4) as $sequence) {
        Badge::factory()->create([
            'code' => "extension-badge-{$sequence}",
            'name' => "Extension Badge {$sequence}",
            'required_achievement_count' => 10 + $sequence,
            'rank' => 10 + $sequence,
        ]);
    }

    $queries = [];
    $expandedProgress = $action->handle($user);
    $expandedQueryCount = count($queries);

    expect($initialQueryCount)->toBe(4)
        ->and($expandedQueryCount)->toBe($initialQueryCount)
        ->and($expandedProgress->unlockedAchievements)->toHaveCount(12)
        ->and($expandedProgress->nextAvailableAchievements)->toHaveCount(14);
});
