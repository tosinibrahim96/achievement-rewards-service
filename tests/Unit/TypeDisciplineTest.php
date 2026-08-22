<?php

declare(strict_types=1);

use App\Actions\Achievements\EvaluatePurchaseAchievements;
use App\Actions\Achievements\UnlockAchievement;
use App\Actions\Purchases\RecordPurchase;
use App\Contracts\Achievements\AchievementProgressCalculator;
use App\Data\Purchases\RecordPurchaseInput;
use App\Data\Purchases\RecordPurchaseResult;
use App\Domain\Achievements\AchievementProgressRegistry;
use App\Domain\Achievements\LifetimeSpendProgressCalculator;
use App\Domain\Achievements\PurchaseCountProgressCalculator;
use App\Domain\Money\Money;
use App\Models\Achievement;
use App\Models\AchievementGroup;
use App\Models\Badge;
use App\Models\Purchase;
use App\Models\User;
use App\Models\UserAchievement;
use ReflectionClass;

it('uses final readonly classes for leaf values and stateless actions without freezing eloquent models', function (): void {
    $finalReadonlyClasses = [
        Money::class,
        RecordPurchaseInput::class,
        RecordPurchaseResult::class,
        RecordPurchase::class,
        AchievementProgressRegistry::class,
        PurchaseCountProgressCalculator::class,
        LifetimeSpendProgressCalculator::class,
        EvaluatePurchaseAchievements::class,
        UnlockAchievement::class,
    ];

    foreach ($finalReadonlyClasses as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();
    }

    $mutableModels = [
        User::class,
        Purchase::class,
        AchievementGroup::class,
        Achievement::class,
        UserAchievement::class,
        Badge::class,
    ];

    foreach ($mutableModels as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeFalse();
    }

    expect((new ReflectionClass(AchievementProgressCalculator::class))->isInterface())->toBeTrue();
});
