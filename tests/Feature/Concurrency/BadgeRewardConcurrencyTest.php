<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Models\CashbackReward;
use App\Models\User;
use App\Models\UserBadge;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BadgeTestData;
use Tests\Support\ConcurrentRunner;

uses(DatabaseMigrations::class);

it('creates one badge and reward when badge evaluations compete', function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);

    (new ConcurrentRunner)->run([
        static fn () => app(EvaluateBadges::class)->handle(User::query()->findOrFail($user->id)),
        static fn () => app(EvaluateBadges::class)->handle(User::query()->findOrFail($user->id)),
    ]);

    expect(UserBadge::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(CashbackReward::query()->whereBelongsTo($user)->count())->toBe(1)
        ->and(UserBadge::query()->whereBelongsTo($user)->distinct('badge_id')->count('badge_id'))->toBe(1)
        ->and(CashbackReward::query()->whereBelongsTo($user)->distinct('user_badge_id')->count('user_badge_id'))->toBe(1);
});
