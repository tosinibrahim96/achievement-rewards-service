<?php

declare(strict_types=1);

use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Domain\Achievements\AchievementProgressRegistry;
use App\Enums\AchievementMetric;
use App\Models\User;
use LogicException;

function calculatorFor(AchievementMetric $metric): AchievementProgressCalculator
{
    return new readonly class($metric) implements AchievementProgressCalculator
    {
        public function __construct(
            private AchievementMetric $achievementMetric,
        ) {}

        public function metric(): AchievementMetric
        {
            return $this->achievementMetric;
        }

        public function progressFor(User $user): int
        {
            return 0;
        }
    };
}

it('resolves exactly one calculator for each metric', function (): void {
    $purchaseCount = calculatorFor(AchievementMetric::PurchaseCount);
    $lifetimeSpend = calculatorFor(AchievementMetric::LifetimeSpend);
    $registry = new AchievementProgressRegistry([$purchaseCount, $lifetimeSpend]);

    expect($registry->for(AchievementMetric::PurchaseCount))->toBe($purchaseCount)
        ->and($registry->for(AchievementMetric::LifetimeSpend))->toBe($lifetimeSpend);
});

it('fails clearly when a metric has no calculator', function (): void {
    expect(fn (): AchievementProgressRegistry => new AchievementProgressRegistry([
        calculatorFor(AchievementMetric::PurchaseCount),
    ]))->toThrow(
        LogicException::class,
        'No achievement progress calculator is registered for [lifetime_spend].',
    );
});

it('fails clearly when a metric has multiple calculators', function (): void {
    expect(fn (): AchievementProgressRegistry => new AchievementProgressRegistry([
        calculatorFor(AchievementMetric::PurchaseCount),
        calculatorFor(AchievementMetric::PurchaseCount),
        calculatorFor(AchievementMetric::LifetimeSpend),
    ]))->toThrow(
        LogicException::class,
        'Multiple achievement progress calculators are registered for [purchase_count].',
    );
});
