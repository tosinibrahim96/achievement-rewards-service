<?php

declare(strict_types=1);

use App\Actions\Auth\LoginCustomer;
use App\Actions\Auth\RegisterCustomer;
use App\Actions\Payouts\RegisterPayoutAccount;
use App\Data\Auth\LoginCustomerInput;
use App\Data\Auth\RegisterCustomerInput;
use App\Data\Payouts\RegisterPayoutAccountInput;

it('marks credential-bearing Action inputs as sensitive', function (string $action): void {
    $parameter = (new ReflectionMethod($action, 'handle'))->getParameters()[0];

    expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    LoginCustomer::class,
    RegisterCustomer::class,
]);

it('marks password constructor parameters as sensitive', function (string $input): void {
    $constructor = (new ReflectionClass($input))->getConstructor();

    expect($constructor)->not->toBeNull();

    $password = collect($constructor?->getParameters() ?? [])
        ->firstWhere(fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'password');

    expect($password)->toBeInstanceOf(ReflectionParameter::class)
        ->and($password->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    LoginCustomerInput::class,
    RegisterCustomerInput::class,
]);

it('marks the complete payout account input at the fallible Action boundary', function (): void {
    $parameter = (new ReflectionMethod(RegisterPayoutAccount::class, 'handle'))->getParameters()[1];

    expect($parameter->getType()?->getName())->toBe(RegisterPayoutAccountInput::class)
        ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

it('marks the full account number as sensitive while constructing payout input', function (): void {
    $constructor = (new ReflectionClass(RegisterPayoutAccountInput::class))->getConstructor();
    $accountNumber = collect($constructor?->getParameters() ?? [])
        ->firstWhere(fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'accountNumber');

    expect($accountNumber)->toBeInstanceOf(ReflectionParameter::class)
        ->and($accountNumber->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
