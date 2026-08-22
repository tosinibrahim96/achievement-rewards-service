<?php

declare(strict_types=1);

use App\Actions\Auth\LoginCustomer;
use App\Actions\Auth\RegisterCustomer;
use App\Data\Auth\LoginCustomerInput;
use App\Data\Auth\RegisterCustomerInput;

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
