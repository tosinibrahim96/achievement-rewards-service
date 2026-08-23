<?php

declare(strict_types=1);

use App\Actions\Achievements\GetUserAchievementProgress;
use App\Http\Controllers\AchievementProgressController;
use App\Http\Resources\AchievementProgressResource;
use App\Models\User;

it('injects the progress Action and accepts a User in show', function (): void {
    $controllerClass = new ReflectionClass(AchievementProgressController::class);
    $constructor = $controllerClass->getConstructor();
    $showMethod = $controllerClass->getMethod('show');
    $showParameters = $showMethod->getParameters();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->getParameters())->toHaveCount(1)
        ->and($constructor?->getParameters()[0]->getType()?->getName())->toBe(GetUserAchievementProgress::class)
        ->and($showParameters)->toHaveCount(1)
        ->and($showParameters[0]->getType()?->getName())->toBe(User::class)
        ->and($showMethod->getReturnType()?->getName())->toBe(AchievementProgressResource::class);
});
