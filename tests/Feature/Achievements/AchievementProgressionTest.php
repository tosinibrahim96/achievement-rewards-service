<?php

declare(strict_types=1);

use App\Actions\Purchases\RecordPurchase;
use App\Data\Purchases\RecordPurchaseInput;
use App\Domain\Money\Money;
use App\Enums\Currency;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use Carbon\CarbonImmutable;
use Database\Seeders\AchievementCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->seed(AchievementCatalogueSeeder::class);
});

function recordQualifyingPurchase(User $user, string $reference, int $amountMinor): Purchase
{
    return app(RecordPurchase::class)->handle(new RecordPurchaseInput(
        userId: $user->id,
        externalReference: $reference,
        amount: new Money($amountMinor, Currency::Ngn),
        completedAt: CarbonImmutable::parse('2026-08-21T14:30:00Z'),
    ))->purchase;
}

/** @return list<string> */
function unlockedCodesFor(User $user): array
{
    return UserAchievement::query()
        ->whereBelongsTo($user)
        ->join('achievements', 'achievements.id', '=', 'user_achievements.achievement_id')
        ->orderBy('user_achievements.id')
        ->pluck('achievements.code')
        ->all();
}

it('unlocks no achievements before a completed purchase exists', function (): void {
    $user = User::factory()->create();

    expect(unlockedCodesFor($user))->toBe([]);
});

it('unlocks every reached purchase count milestone once', function (int $count, array $expected): void {
    $user = User::factory()->create();

    if ($count > 1) {
        Purchase::factory()->count($count - 1)->for($user)->create(['amount_minor' => 1]);
    }

    recordQualifyingPurchase($user, "ORDER-COUNT-{$count}", 1);

    expect(unlockedCodesFor($user))->toBe($expected);
})->with([
    'first purchase' => [1, ['first-purchase']],
    'second purchase' => [2, ['first-purchase']],
    'third purchase' => [3, ['first-purchase', 'three-purchases']],
    'fifth purchase' => [5, ['first-purchase', 'three-purchases', 'five-purchases']],
    'tenth purchase' => [10, ['first-purchase', 'three-purchases', 'five-purchases', 'ten-purchases']],
    'twenty-fifth purchase' => [25, [
        'first-purchase', 'three-purchases', 'five-purchases', 'ten-purchases', 'twenty-five-purchases',
    ]],
]);

it('does not unlock a lifetime spend achievement below its first threshold', function (): void {
    $user = User::factory()->create();

    recordQualifyingPurchase($user, 'ORDER-SPEND-BELOW', 499_999);

    expect(unlockedCodesFor($user))->toBe(['first-purchase']);
});

it('unlocks a lifetime spend achievement at the exact threshold', function (): void {
    $user = User::factory()->create();

    recordQualifyingPurchase($user, 'ORDER-SPEND-EXACT', 500_000);

    expect(unlockedCodesFor($user))->toBe(['first-purchase', 'five-thousand-spent']);
});

it('accumulates integer minor units across completed purchases', function (): void {
    $user = User::factory()->create();

    recordQualifyingPurchase($user, 'ORDER-SPEND-PART-1', 300_000);
    recordQualifyingPurchase($user, 'ORDER-SPEND-PART-2', 700_000);

    expect(unlockedCodesFor($user))->toBe([
        'first-purchase',
        'five-thousand-spent',
        'ten-thousand-spent',
    ]);
});

it('unlocks every crossed spend threshold in deterministic order', function (): void {
    $user = User::factory()->create();

    recordQualifyingPurchase($user, 'ORDER-SPEND-LARGE', 2_500_000);

    expect(unlockedCodesFor($user))->toBe([
        'first-purchase',
        'five-thousand-spent',
        'ten-thousand-spent',
        'twenty-five-thousand-spent',
    ]);
});

it('ignores inactive achievement definitions', function (): void {
    $user = User::factory()->create();
    Achievement::query()->where('code', 'first-purchase')->update(['is_active' => false]);

    recordQualifyingPurchase($user, 'ORDER-INACTIVE', 1);

    expect(unlockedCodesFor($user))->toBe([]);
});

it('ignores inactive achievement groups', function (): void {
    $user = User::factory()->create();
    AchievementGroup::query()->where('code', 'purchase-count')->update(['is_active' => false]);

    recordQualifyingPurchase($user, 'ORDER-INACTIVE-GROUP', 1);

    expect(unlockedCodesFor($user))->toBe([]);
});
