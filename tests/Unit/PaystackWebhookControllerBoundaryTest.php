<?php

declare(strict_types=1);

use App\Actions\Cashback\HandlePaystackWebhook;
use App\Http\Controllers\PaystackWebhookController;
use Illuminate\Http\Request;
use SensitiveParameter;

it('keeps callback orchestration in the Action and the raw HTTP request on invoke', function (): void {
    $controller = new ReflectionClass(PaystackWebhookController::class);
    $constructor = $controller->getConstructor();
    $invokeParameters = $controller->getMethod('__invoke')->getParameters();

    expect($constructor)->not->toBeNull()
        ->and($constructor?->getParameters())->toHaveCount(1)
        ->and($constructor?->getParameters()[0]->getType()?->getName())->toBe(HandlePaystackWebhook::class)
        ->and($invokeParameters)->toHaveCount(1)
        ->and($invokeParameters[0]->getType()?->getName())->toBe(Request::class)
        ->and($invokeParameters[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
