<?php

declare(strict_types=1);

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Actions\Achievements\UnlockAchievement;
use App\Events\AchievementUnlocked;
use App\Models\Achievement;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use Database\Seeders\AchievementCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

uses(DatabaseMigrations::class);

it('exposes the exact achievement event contract for a newly persisted unlock', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create(['name' => 'First Purchase']);
    $purchase = Purchase::factory()->for($user)->create();

    app(UnlockAchievement::class)->handle($user, $achievement, $purchase);

    Event::assertDispatched(AchievementUnlocked::class, function (AchievementUnlocked $event) use ($user): bool {
        expect(array_keys(get_object_vars($event)))->toBe(['achievement_name', 'user'])
            ->and($event->achievement_name)->toBe('First Purchase')
            ->and($event->user)->toBeInstanceOf(User::class)
            ->and($event->user->is($user))->toBeTrue();

        return true;
    });
});

it('dispatches AchievementUnlocked only after the surrounding transaction commits', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $purchase = Purchase::factory()->for($user)->create();

    DB::transaction(function () use ($user, $achievement, $purchase): void {
        app(UnlockAchievement::class)->handle($user, $achievement, $purchase);

        Event::assertNotDispatched(AchievementUnlocked::class);
    });

    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
});

it('does not retain an unlock or dispatch its event when the transaction rolls back', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $purchase = Purchase::factory()->for($user)->create();

    try {
        DB::transaction(function () use ($user, $achievement, $purchase): never {
            app(UnlockAchievement::class)->handle($user, $achievement, $purchase);

            throw new RuntimeException('Force achievement rollback.');
        });
    } catch (RuntimeException) {
        // The rollback is the behaviour under test.
    }

    expect(UserAchievement::query()->count())->toBe(0);
    Event::assertNotDispatched(AchievementUnlocked::class);
});

it('does not dispatch a second event when an existing unlock is replayed', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    $purchase = Purchase::factory()->for($user)->create();
    $unlockAchievement = app(UnlockAchievement::class);

    $first = $unlockAchievement->handle($user, $achievement, $purchase);
    $replayed = $unlockAchievement->handle($user, $achievement, $purchase);

    expect($first)->toBeInstanceOf(UserAchievement::class)
        ->and($replayed)->toBeNull()
        ->and(UserAchievement::query()->count())->toBe(1);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
});

it('dispatches one ordered event for every newly crossed achievement', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchases = Purchase::factory()->count(5)->for($user)->create(['amount_minor' => 1]);

    app(EvaluatePurchaseAchievements::class)->handle($purchases->last());

    $names = Event::dispatched(AchievementUnlocked::class)
        ->map(static fn (array $dispatch): string => $dispatch[0]->achievement_name)
        ->all();

    expect($names)->toBe(['First Purchase', '3 Purchases', '5 Purchases']);
});
