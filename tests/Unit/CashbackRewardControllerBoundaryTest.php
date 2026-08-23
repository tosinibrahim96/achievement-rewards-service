<?php

declare(strict_types=1);

use App\Actions\Cashback\ListCashbackRewards;
use App\Http\Controllers\CashbackRewardController;
use App\Http\Requests\Cashback\ListCashbackRewardsRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

it('keeps the listing Action in the constructor and request-scoped values on index', function (): void {
    $controller = new ReflectionClass(CashbackRewardController::class);
    $constructor = $controller->getConstructor();
    $indexParameters = $controller->getMethod('index')->getParameters();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->getParameters())->toHaveCount(1)
        ->and($constructor?->getParameters()[0]->getType()?->getName())->toBe(ListCashbackRewards::class)
        ->and($indexParameters)->toHaveCount(2)
        ->and($indexParameters[0]->getType()?->getName())->toBe(ListCashbackRewardsRequest::class)
        ->and($indexParameters[1]->getType()?->getName())->toBe(User::class)
        ->and($indexParameters[1]->getAttributes(CurrentUser::class))->toHaveCount(1);
});
