<?php

declare(strict_types=1);

use App\Actions\Payouts\RegisterPayoutAccount;
use App\Http\Controllers\PayoutAccountController;
use App\Http\Requests\Payouts\RegisterPayoutAccountRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

it('keeps the payout Action in the constructor and request-scoped values on update', function (): void {
    $controller = new ReflectionClass(PayoutAccountController::class);
    $constructor = $controller->getConstructor();
    $updateParameters = $controller->getMethod('update')->getParameters();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->getParameters())->toHaveCount(1)
        ->and($constructor?->getParameters()[0]->getType()?->getName())->toBe(RegisterPayoutAccount::class)
        ->and($updateParameters)->toHaveCount(2)
        ->and($updateParameters[0]->getType()?->getName())->toBe(RegisterPayoutAccountRequest::class)
        ->and($updateParameters[1]->getType()?->getName())->toBe(User::class)
        ->and($updateParameters[1]->getAttributes(CurrentUser::class))->toHaveCount(1);
});
