<?php

declare(strict_types=1);

use App\Actions\Auth\LoginCustomer;
use App\Actions\Auth\RegisterCustomer;
use App\Actions\Auth\RevokeCurrentToken;
use App\Http\Controllers\AuthController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

it('keeps auth Actions in the constructor and request-scoped values on controller methods', function (): void {
    $controller = new ReflectionClass(AuthController::class);
    $constructor = $controller->getConstructor();

    expect($constructor)->not->toBeNull();

    $constructorDependencies = array_map(
        static fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
            ? $parameter->getType()->getName()
            : null,
        $constructor?->getParameters() ?? [],
    );

    expect($constructorDependencies)->toBe([
        RegisterCustomer::class,
        LoginCustomer::class,
        RevokeCurrentToken::class,
    ]);

    expect($controller->getMethod('register')->getParameters())->toHaveCount(1)
        ->and($controller->getMethod('register')->getParameters()[0]->getType()?->getName())->toBe(RegisterRequest::class)
        ->and($controller->getMethod('login')->getParameters())->toHaveCount(1)
        ->and($controller->getMethod('login')->getParameters()[0]->getType()?->getName())->toBe(LoginRequest::class);

    foreach (['logout', 'me'] as $methodName) {
        $parameters = $controller->getMethod($methodName)->getParameters();

        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getType()?->getName())->toBe(User::class)
            ->and($parameters[0]->getAttributes(CurrentUser::class))->toHaveCount(1);
    }
});
