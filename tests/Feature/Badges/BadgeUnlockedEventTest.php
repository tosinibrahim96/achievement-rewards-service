<?php

declare(strict_types=1);

use App\Actions\Badges\EvaluateBadges;
use App\Events\BadgeUnlocked;
use App\Models\User;
use App\Models\UserBadge;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\BadgeTestData;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
});

it('exposes the exact badge event contract for a newly persisted award', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);

    app(EvaluateBadges::class)->handle($user);

    Event::assertDispatched(BadgeUnlocked::class, function (BadgeUnlocked $event) use ($user): bool {
        expect(array_keys(get_object_vars($event)))->toBe(['badge_name', 'user'])
            ->and($event->badge_name)->toBe('Beginner')
            ->and($event->user)->toBeInstanceOf(User::class)
            ->and($event->user->is($user))->toBeTrue();

        return true;
    });
});

it('dispatches BadgeUnlocked only after the surrounding transaction commits', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);

    DB::transaction(function () use ($user): void {
        app(EvaluateBadges::class)->handle($user);

        Event::assertNotDispatched(BadgeUnlocked::class);
    });

    Event::assertDispatchedTimes(BadgeUnlocked::class, 1);
});

it('rolls back the badge and event together', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 1);

    try {
        DB::transaction(function () use ($user): never {
            app(EvaluateBadges::class)->handle($user);

            throw new RuntimeException('Force badge rollback.');
        });
    } catch (RuntimeException) {
        // The rollback is the behaviour under test.
    }

    expect(UserBadge::query()->count())->toBe(0);
    Event::assertNotDispatched(BadgeUnlocked::class);
});

it('emits each newly crossed badge once and emits nothing on replay', function (): void {
    Event::fake([BadgeUnlocked::class]);
    $user = User::factory()->create();
    BadgeTestData::giveAchievements($user, 8);
    $evaluateBadges = app(EvaluateBadges::class);

    $evaluateBadges->handle($user);
    $evaluateBadges->handle($user);

    $names = Event::dispatched(BadgeUnlocked::class)
        ->map(static fn (array $dispatch): string => $dispatch[0]->badge_name)
        ->all();

    expect($names)->toBe(['Beginner', 'Intermediate', 'Advanced']);
});
