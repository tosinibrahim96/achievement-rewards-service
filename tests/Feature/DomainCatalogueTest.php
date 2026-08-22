<?php

declare(strict_types=1);

use App\Enums\AchievementMetric;
use App\Enums\Currency;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Badge;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use Database\Seeders\AchievementCatalogueSeeder;
use Database\Seeders\BadgeCatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('seeds the frozen achievement and badge catalogues idempotently', function (): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);

    $groups = AchievementGroup::query()->orderBy('sort_order')->get();
    $achievements = Achievement::query()
        ->join('achievement_groups', 'achievement_groups.id', '=', 'achievements.achievement_group_id')
        ->orderBy('achievement_groups.sort_order')
        ->orderBy('achievements.sort_order')
        ->get(['achievements.*']);
    $badges = Badge::query()->orderBy('rank')->get();

    expect($groups)->toHaveCount(2)
        ->and($groups->map->only(['code', 'name', 'metric', 'sort_order', 'is_active'])->all())->toBe([
            [
                'code' => 'purchase-count',
                'name' => 'Purchase Count',
                'metric' => AchievementMetric::PurchaseCount,
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'lifetime-spend',
                'name' => 'Lifetime Spend',
                'metric' => AchievementMetric::LifetimeSpend,
                'sort_order' => 2,
                'is_active' => true,
            ],
        ])
        ->and($achievements->map->only(['code', 'name', 'threshold', 'sort_order', 'is_active'])->all())->toBe([
            ['code' => 'first-purchase', 'name' => 'First Purchase', 'threshold' => 1, 'sort_order' => 1, 'is_active' => true],
            ['code' => 'three-purchases', 'name' => '3 Purchases', 'threshold' => 3, 'sort_order' => 2, 'is_active' => true],
            ['code' => 'five-purchases', 'name' => '5 Purchases', 'threshold' => 5, 'sort_order' => 3, 'is_active' => true],
            ['code' => 'ten-purchases', 'name' => '10 Purchases', 'threshold' => 10, 'sort_order' => 4, 'is_active' => true],
            ['code' => 'twenty-five-purchases', 'name' => '25 Purchases', 'threshold' => 25, 'sort_order' => 5, 'is_active' => true],
            ['code' => 'five-thousand-spent', 'name' => 'NGN 5,000 Spent', 'threshold' => 500_000, 'sort_order' => 1, 'is_active' => true],
            ['code' => 'ten-thousand-spent', 'name' => 'NGN 10,000 Spent', 'threshold' => 1_000_000, 'sort_order' => 2, 'is_active' => true],
            ['code' => 'twenty-five-thousand-spent', 'name' => 'NGN 25,000 Spent', 'threshold' => 2_500_000, 'sort_order' => 3, 'is_active' => true],
            ['code' => 'fifty-thousand-spent', 'name' => 'NGN 50,000 Spent', 'threshold' => 5_000_000, 'sort_order' => 4, 'is_active' => true],
            ['code' => 'one-hundred-thousand-spent', 'name' => 'NGN 100,000 Spent', 'threshold' => 10_000_000, 'sort_order' => 5, 'is_active' => true],
        ])
        ->and($badges->map->only(['code', 'name', 'required_achievement_count', 'rank', 'is_active'])->all())->toBe([
            ['code' => 'beginner', 'name' => 'Beginner', 'required_achievement_count' => 1, 'rank' => 1, 'is_active' => true],
            ['code' => 'intermediate', 'name' => 'Intermediate', 'required_achievement_count' => 4, 'rank' => 2, 'is_active' => true],
            ['code' => 'advanced', 'name' => 'Advanced', 'required_achievement_count' => 8, 'rank' => 3, 'is_active' => true],
            ['code' => 'master', 'name' => 'Master', 'required_achievement_count' => 10, 'rank' => 4, 'is_active' => true],
        ]);
});

it('keeps the cashback rule in version-controlled configuration', function (): void {
    expect(config('rewards.badge_cashback_amount_minor'))->toBe(30_000)
        ->and(config('rewards.currency'))->toBe(Currency::Ngn->value);
});

it('enforces catalogue checks and uniqueness in postgres', function (string $case): void {
    $this->seed([AchievementCatalogueSeeder::class, BadgeCatalogueSeeder::class]);
    $now = now();
    $purchaseCountGroup = AchievementGroup::query()->where('code', 'purchase-count')->sole();

    $invalidInsert = match ($case) {
        'unsupported metric' => fn (): bool => DB::table('achievement_groups')->insert([
            'code' => 'invalid-metric',
            'name' => 'Invalid Metric',
            'metric' => 'order_value_average',
            'sort_order' => 3,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        'non-positive achievement threshold' => fn (): bool => DB::table('achievements')->insert([
            'achievement_group_id' => $purchaseCountGroup->id,
            'code' => 'zero-purchases',
            'name' => 'Zero Purchases',
            'threshold' => 0,
            'sort_order' => 6,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        'duplicate group threshold' => fn (): bool => DB::table('achievements')->insert([
            'achievement_group_id' => $purchaseCountGroup->id,
            'code' => 'another-first-purchase',
            'name' => 'Another First Purchase',
            'threshold' => 1,
            'sort_order' => 6,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        'duplicate badge rank' => fn (): bool => DB::table('badges')->insert([
            'code' => 'another-beginner',
            'name' => 'Another Beginner',
            'required_achievement_count' => 2,
            'rank' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        default => throw new LogicException("Unknown constraint case [{$case}]."),
    };

    expect($invalidInsert)->toThrow(QueryException::class);
})->with([
    'unsupported metric',
    'non-positive achievement threshold',
    'duplicate group threshold',
    'duplicate badge rank',
]);

it('enforces purchase amount and currency invariants in postgres', function (): void {
    $user = User::factory()->create();
    $basePurchase = [
        'user_id' => $user->id,
        'external_reference' => 'ORDER-CONSTRAINT',
        'amount_minor' => 1,
        'currency' => Currency::Ngn->value,
        'completed_at' => now(),
        'correlation_id' => (string) Str::ulid(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn (): bool => DB::table('purchases')->insert([
        ...$basePurchase,
        'amount_minor' => 0,
    ]))->toThrow(QueryException::class)
        ->and(fn (): bool => DB::table('purchases')->insert([
            ...$basePurchase,
            'external_reference' => 'ORDER-CURRENCY-CONSTRAINT',
            'currency' => 'USD',
        ]))->toThrow(QueryException::class);
});

it('enforces one unlock per user and achievement', function (): void {
    $this->seed(AchievementCatalogueSeeder::class);
    $user = User::factory()->create();
    $purchase = Purchase::factory()->for($user)->create();
    $achievement = Achievement::query()->where('code', 'first-purchase')->sole();

    UserAchievement::factory()->create([
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
        'triggered_by_purchase_id' => $purchase->id,
    ]);

    expect(fn (): UserAchievement => UserAchievement::factory()->create([
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
        'triggered_by_purchase_id' => $purchase->id,
    ]))->toThrow(QueryException::class);
});
