<?php

declare(strict_types=1);

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Actions\Achievements\UnlockAchievement;
use App\Enums\AchievementMetric;
use App\Events\AchievementUnlocked;
use App\Http\Middleware\AssignRequestId;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use Database\Seeders\AchievementCatalogueSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
        /*
         * The rollback is the behaviour under test.
         */
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

it('calculates progress once when active groups use the same metric', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create(['amount_minor' => 1]);
    $extraPurchaseCountGroup = AchievementGroup::factory()->create([
        'code' => 'repeat-purchase-count',
        'metric' => AchievementMetric::PurchaseCount,
        'is_active' => true,
    ]);
    $achievementUsingSameMetric = Achievement::factory()->for($extraPurchaseCountGroup, 'group')->create([
        'name' => 'Repeat Purchase Count',
        'threshold' => 1,
        'is_active' => true,
    ]);
    $progressQueries = [];

    DB::listen(static function (QueryExecuted $query) use (&$progressQueries): void {
        if (str_contains($query->sql, 'from "purchases"')) {
            $progressQueries[] = $query->sql;
        }
    });

    app(EvaluatePurchaseAchievements::class)->handle($purchase);

    expect($progressQueries)->toHaveCount(2)
        ->and($progressQueries[0])->toContain('count(*)')
        ->and($progressQueries[1])->toContain('sum("amount_minor")')
        ->and(UserAchievement::query()
            ->where('user_id', $user->id)
            ->where('achievement_id', $achievementUsingSameMetric->id)
            ->exists())->toBeTrue();

    Event::assertDispatched(
        AchievementUnlocked::class,
        fn (AchievementUnlocked $event): bool => $event->achievement_name === $achievementUsingSameMetric->name
            && $event->user->is($user),
    );
});

it('logs all newly unlocked achievement names with only allowed fields', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchases = Purchase::factory()->count(5)->for($user)->create(['amount_minor' => 1]);
    $purchase = $purchases->last();
    $requestId = 'request-achievement-evaluation';
    $previousCorrelationId = 'previous-workflow';
    $loggedContext = [];
    Context::add(AssignRequestId::ATTRIBUTE, $requestId);
    Context::add('correlation_id', $previousCorrelationId);

    Log::shouldReceive('info')->once()->with(
        'achievement.evaluation.completed',
        Mockery::on(function (array $context) use ($purchase, $requestId, &$loggedContext): bool {
            $loggedContext = $context;

            return Context::get(AssignRequestId::ATTRIBUTE) === $requestId
                && Context::get('correlation_id') === $purchase->correlation_id;
        }),
    );

    app(EvaluatePurchaseAchievements::class)->handle($purchase);

    expect(array_keys($loggedContext))->toBe([
        'purchase_id',
        'user_id',
        'correlation_id',
        'unlocked_count',
        'unlocked_achievement_names',
    ])->and($loggedContext)->toBe([
        'purchase_id' => $purchase->id,
        'user_id' => $user->id,
        'correlation_id' => $purchase->correlation_id,
        'unlocked_count' => 3,
        'unlocked_achievement_names' => ['First Purchase', '3 Purchases', '5 Purchases'],
    ])->and(Context::get('correlation_id'))->toBe($previousCorrelationId);
});

it('logs no new achievements when the same purchase is checked again', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create(['amount_minor' => 1]);
    $evaluateAchievements = app(EvaluatePurchaseAchievements::class);
    $evaluateAchievements->handle($purchase);
    Log::spy();

    $evaluateAchievements->handle($purchase);

    Log::shouldHaveReceived('info')->once()->with('achievement.evaluation.completed', [
        'purchase_id' => $purchase->id,
        'user_id' => $user->id,
        'correlation_id' => $purchase->correlation_id,
        'unlocked_count' => 0,
        'unlocked_achievement_names' => [],
    ]);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
});

it('does not log achievement results when the outer transaction rolls back', function (): void {
    Event::fake([AchievementUnlocked::class]);
    Log::spy();
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create(['amount_minor' => 1]);

    try {
        DB::transaction(function () use ($purchase): never {
            app(EvaluatePurchaseAchievements::class)->handle($purchase);

            Log::shouldNotHaveReceived('info', [
                'achievement.evaluation.completed',
                Mockery::type('array'),
            ]);

            throw new RuntimeException('Force achievement evaluation rollback.');
        });
    } catch (RuntimeException) {
        /*
         * The rollback is the behaviour under test.
         */
    }

    expect(UserAchievement::query()->count())->toBe(0);
    Log::shouldNotHaveReceived('info', [
        'achievement.evaluation.completed',
        Mockery::type('array'),
    ]);
});

it('keeps unlocked achievements when achievement logging fails', function (): void {
    Event::fake([AchievementUnlocked::class]);
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create(['amount_minor' => 1]);
    Log::spy();
    Log::shouldReceive('info')
        ->once()
        ->with('achievement.evaluation.completed', Mockery::type('array'))
        ->andThrow(new RuntimeException('achievement log unavailable'));

    app(EvaluatePurchaseAchievements::class)->handle($purchase);

    expect(UserAchievement::query()->whereBelongsTo($user)->count())->toBe(1);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
});
